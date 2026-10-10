<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Notification\EnsureDefaultNotifications;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueuedNotification;
use Shondalai\VidyaLMS\Core\Domain\Organisation\OrganisationInvitationDelivery;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlNotificationQueueRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\OrganisationInvitationTrigger;
use Shondalai\VidyaLMS\Core\Registry\Context\InvitationMatchContext;
use Shondalai\VidyaLMS\Core\Registry\InvitationMatch\InvitationMatchRegistry;
use Shondalai\VidyaLMS\Core\Registry\Notification\Notification;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;
use Shondalai\VidyaLMS\Core\Registry\Notification\SensitiveNotificationDelivery;
use Shondalai\VidyaLMS\Core\Registry\Notification\SensitiveNotificationMessage;

/** Same-transaction encrypted outbox; all SMTP remains in NotificationSender. */
final class EncryptedInvitationDelivery implements OrganisationInvitationDelivery, SensitiveNotificationDelivery
{
    public const TABLE = 'org_notification_secrets';

    /** Stable entity key within the notification outbox's 20-character SQL contract. */
    public const SUBJECT_TYPE = 'org_invitation';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly SqlNotificationQueueRepository $queueRepository,
        private readonly InvitationSecretBox $box,
        private readonly ClockPort $clock,
        private readonly UsersPort $users,
        private readonly EnsureDefaultNotifications $templates,
        private readonly string $acceptanceUrl,
        /**
         * The same policy the acceptance ceremony asks, asked once more before
         * a secret is put in front of an address.
         *
         * Required rather than defaulted, for the reason the acceptance path
         * states: an empty registry fails closed, and here that means a proof
         * mail is never rendered, which is a visible outage rather than a
         * silent widening.
         */
        private readonly InvitationMatchRegistry $matches,
    ) {
    }

    public function queue(
        string $kind,
        int $organisationId,
        int $invitationId,
        ?int $claimId,
        string $recipientEmail,
        string $secret,
        string $expiresAt,
        string $dedupeKey,
    ): void {
        if (!$this->box->available() || !str_starts_with($this->acceptanceUrl, 'https://')) {
            throw new \RuntimeException('Secure invitation delivery is not configured.');
        }
        if (!in_array($kind, ['invitation', 'proof'], true)) {
            throw new \InvalidArgumentException('Unsupported protected message kind.');
        }
        $this->templates->execute();
        $trigger = (new OrganisationInvitationTrigger($kind === 'proof'))->key();
        $invitation = $this->database->selectOne(Query::from('invitations')->select(['revision'])
            ->where('id', '=', $invitationId)->where('organisation_id', '=', $organisationId));
        if ($invitation === null) {
            throw new \RuntimeException('Protected notification invitation is unavailable.');
        }
        $digest = hash('sha256', $dedupeKey);
        $queueId = $this->queueRepository->enqueue(new QueuedNotification(
            null,
            null,
            $trigger,
            'account',
            NotificationClass::Transactional,
            $trigger,
            $dedupeKey,
            'once',
            $digest,
            $this->clock->now(),
            recipientUserId: 0,
            channelKey: 'email',
            subjectType: self::SUBJECT_TYPE,
            subjectId: $invitationId,
            parameters: ['organisation_id' => $organisationId, 'invitation_id' => $invitationId, 'claim_id' => $claimId,
                'invitation_revision' => (int) $invitation['revision']],
        ));
        if ($queueId === null) {
            return;
        }
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $this->database->insert(self::TABLE, [
            'queue_id' => $queueId, 'organisation_id' => $organisationId, 'invitation_id' => $invitationId,
            'claim_id' => $claimId, 'expires_at' => $expiresAt,
            'sealed_payload' => $this->box->seal(json_encode([
                'v' => 1, 'queue_digest' => $digest, 'recipient' => $recipientEmail, 'secret' => $secret,
            ], JSON_THROW_ON_ERROR)),
            'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    public function handles(QueuedNotification $row): bool
    {
        return in_array($row->triggerKey, [
            (new OrganisationInvitationTrigger())->key(), (new OrganisationInvitationTrigger(true))->key(),
        ], true);
    }

    public function prepare(QueuedNotification $row): ?SensitiveNotificationMessage
    {
        $stored = $this->database->selectOne(Query::from(self::TABLE)->where('queue_id', '=', $row->id));
        if ($stored === null || (string) $stored['expires_at'] <= $this->now()) {
            return null;
        }
        $payload = json_decode($this->box->open((string) $stored['sealed_payload']), true, 16, JSON_THROW_ON_ERROR);
        if (
            !is_array($payload) || ($payload['v'] ?? null) !== 1 || ($payload['queue_digest'] ?? '') !== $row->dedupeKey
            || !is_string($payload['recipient'] ?? null) || !is_string($payload['secret'] ?? null)
        ) {
            throw new \RuntimeException('Protected notification payload is invalid.');
        }
        $orgId = (int) $stored['organisation_id'];
        $org = $this->database->selectOne(Query::from('organisations')->where('id', '=', $orgId));
        $invitation = $this->database->selectOne(Query::from('invitations')
            ->where('organisation_id', '=', $orgId)->where('id', '=', $stored['invitation_id']));
        if (
            $org === null || $org['state'] !== 'active' || $invitation === null || $invitation['state'] !== 'pending'
            || (string) $invitation['expires_at'] <= $this->now() || $payload['recipient'] !== $invitation['email']
        ) {
            return null;
        }
        $parameters = ['organisation_name' => (string) $org['name']];
        $recipientId = 0;
        if ($stored['claim_id'] !== null) {
            $claim = $this->database->selectOne(Query::from('org_invitation_claims')->where('id', '=', $stored['claim_id'])
                ->where('organisation_id', '=', $orgId)->where('invitation_id', '=', $stored['invitation_id']));
            $user = $claim === null ? null : $this->users->findById((int) $claim['user_id']);
            if (
                $claim === null || $claim['state'] !== 'pending' || (string) $claim['expires_at'] <= $this->now()
                || (int) $claim['invitation_revision'] !== (int) $invitation['revision'] || $user === null
                || !hash_equals((string) $claim['proof_hash'], hash('sha256', $payload['secret']))
            ) {
                return null;
            }
            // The third of the three comparisons that used to be spelled out
            // by hand, and the one that decides whether a code minted for an
            // address the account no longer holds may still be delivered to
            // it. The sealed recipient is the invited address the ceremony
            // has already matched against the invitation row above.
            if (
                !$this->matches->matches(InvitationMatchRegistry::keyFor($org), InvitationMatchContext::forDelivery(
                    $orgId,
                    (int) $invitation['id'],
                    (int) $invitation['revision'],
                    $payload['recipient'],
                    $user->email,
                    $user->id,
                    (int) $stored['claim_id'],
                ))
            ) {
                return null;
            }
            $recipientId = $user->id;
            $parameters['proof_code'] = $payload['secret'];
        } else {
            if (!hash_equals((string) $invitation['token_hash'], hash('sha256', $payload['secret']))) {
                return null;
            }
            // The secret is a fragment, never a server access-log query.
            $parameters['invitation_url'] = $this->acceptanceUrl . '#/organisation-invitation/' . rawurlencode($payload['secret']);
        }
        return new SensitiveNotificationMessage($payload['recipient'], new Notification(
            $recipientId,
            $row->templateKey,
            $parameters,
            $row->eventReference,
            NotificationClass::Transactional,
            'account',
        ));
    }

    public function purge(int $queueId): void
    {
        $this->database->delete(self::TABLE, ['queue_id' => $queueId]);
    }

    /** Only authenticated, provider-message-correlated terminal feedback reaches this seam. */
    public function feedback(
        QueuedNotification $row,
        \Shondalai\VidyaLMS\Core\Registry\Notification\Feedback\DeliveryFeedback $report,
    ): void {
        if (!$this->handles($row) || !$report->suppressesImmediately() || $report->recipient === null) {
            return;
        }
        $this->database->transactional(function () use ($row, $report): void {
            $orgId = (int) ($row->parameters['organisation_id'] ?? 0);
            $invitationId = (int) ($row->parameters['invitation_id'] ?? 0);
            $revision = (int) ($row->parameters['invitation_revision'] ?? 0);
            $invitation = $this->database->selectOne(Query::from('invitations')->where('organisation_id', '=', $orgId)
                ->where('id', '=', $invitationId)->where('revision', '=', $revision)->where('state', '=', 'pending'));
            if ($invitation === null || strtolower(trim((string) $report->recipient)) !== (string) $invitation['email']) {
                return;
            }
            $org = $this->database->selectOne(Query::from('organisations')->where('id', '=', $orgId));
            if ($org === null) {
                return;
            }
            $next = (int) $org['revision'] + 1;
            if (
                $this->database->update(
                    'organisations',
                    ['revision' => $next, 'updated_at' => $this->now()],
                    ['id' => $orgId, 'revision' => (int) $org['revision']]
                ) !== 1
            ) {
                throw new \Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException('Invitation feedback must be retried.');
            }
            if (
                $this->database->update(
                    'invitations',
                    ['state' => 'bounced', 'pending_key' => null, 'token_hash' => null,
                    'revision' => $revision + 1, 'updated_at' => $this->now()],
                    ['id' => $invitationId, 'organisation_id' => $orgId, 'revision' => $revision, 'state' => 'pending']
                ) !== 1
            ) {
                throw new \Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException('Invitation feedback must be retried.');
            }
            $this->database->update(
                'org_invitation_claims',
                ['state' => 'revoked', 'proof_hash' => null],
                ['organisation_id' => $orgId, 'invitation_id' => $invitationId, 'state' => 'pending']
            );
            $this->database->delete(self::TABLE, ['organisation_id' => $orgId, 'invitation_id' => $invitationId]);
            $this->database->insert('org_membership_events', [
                'organisation_id' => $orgId, 'actor_id' => 0, 'subject_id' => null,
                'event_key' => 'invitation.bounced', 'organisation_revision' => $next,
                'details' => json_encode(['invitation_id' => $invitationId, 'feedback' => $report->type], JSON_THROW_ON_ERROR),
                'created_at' => $this->now(), 'updated_at' => $this->now(),
            ]);
        });
    }

    public function prune(int $limit): void
    {
        foreach (
            $this->database->selectAll(Query::from(self::TABLE)->select(['id'])
            ->where('expires_at', '<=', $this->now())->orderBy('id')->limit(max(1, min(100, $limit)))) as $row
        ) {
            $this->database->delete(self::TABLE, ['id' => (int) $row['id']]);
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }
}
