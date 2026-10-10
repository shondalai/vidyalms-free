<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\EncryptedInvitationDelivery;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Notification\NotificationDigest;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class EncryptedInvitationDeliveryTest extends TestCase
{
    private TestPorts $env;
    private CoreKernel $kernel;
    private int $sequence = 0;
    private int $orgId;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        // SQLite ignores VARCHAR lengths; mirror the shipped MySQL column so
        // invitation and mailbox-proof enqueue cannot pass by silently truncating.
        $subjectLength = SchemaDefinition::load()->table('notification_queue')->column('subject_type')->length;
        self::assertNotNull($subjectLength);
        $this->env->database->pdo()->exec(sprintf(
            'CREATE TRIGGER notification_subject_length BEFORE INSERT ON vidyalms_notification_queue '
                . 'WHEN length(NEW.subject_type) > %d BEGIN '
                . "SELECT RAISE(ABORT, 'notification subject_type exceeds schema length'); END",
            $subjectLength,
        ));
        $this->env->authorization->grant(1, Capability::MANAGE);
        $this->env->users->add(1, 'Owner', 'owner@example.test');
        $this->env->users->add(2, 'New member', 'member@example.test');
        $this->kernel = new CoreKernel($this->env->ports, [
            'organisation_invitation_secret' => str_repeat('fixture-secret-', 4),
            'organisation_invitation_url' => 'https://example.test/membership',
            'envelope_domain' => 'example.test',
        ]);
        $response = $this->call('POST', '/manage/organisations', ['name' => 'Acme', 'slug' => 'acme']);
        self::assertSame(201, $response->status);
        $this->orgId = (int) self::data($response)['id'];
    }

    public function testRealQueueEncryptsSecretsAndDeliversThenPurgesWithoutAnSmtpCallAtEnqueue(): void
    {
        $this->issue();
        $queued = $this->env->database->selectOne(Query::from('notification_queue'));
        self::assertNotNull($queued);
        self::assertSame('org_invitation', $queued['subject_type']);
        $hydrated = $this->kernel->notificationQueue->find((int) $queued['id']);
        self::assertNotNull($hydrated);
        self::assertSame('org_invitation', $hydrated->subjectType);
        self::assertCount(0, $this->env->mailer->sent);
        $row = $this->env->database->selectOne(Query::from(EncryptedInvitationDelivery::TABLE));
        self::assertNotNull($row);
        self::assertStringNotContainsString('member@example.test', (string) $row['sealed_payload']);
        self::assertStringNotContainsString('CODE1', (string) $row['sealed_payload']);
        $queueJson = json_encode($this->env->database->selectAll(Query::from('notification_queue')), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('member@example.test', $queueJson);
        self::assertStringNotContainsString('CODE1', $queueJson);
        self::assertSame(1, $this->kernel->notificationSender->drain());
        self::assertCount(1, $this->env->mailer->sent);
        $mail = $this->env->mailer->sent[0];
        self::assertSame('member@example.test', $mail->recipientEmail);
        self::assertSame([], $mail->parameters);
        self::assertStringContainsString('https://example.test/membership#/organisation-invitation/', $mail->bodyText ?? '');
        self::assertStringContainsString('<table', $mail->bodyHtml ?? '');
        self::assertSame('auto-generated', $mail->header('Auto-Submitted'));
        self::assertSame(0, $this->env->database->count(Query::from(EncryptedInvitationDelivery::TABLE)));
        self::assertSame(0, $this->env->database->count(Query::from('email_log')));
        self::assertSame(0, $this->kernel->notificationSender->drain());
    }

    public function testFreshMailboxProofIsDeliveredThroughTheSameProtectedQueueAndThenAccepted(): void
    {
        $this->issue();
        self::assertSame(1, $this->kernel->notificationSender->drain());
        $body = $this->env->mailer->sent[0]->bodyText ?? '';
        self::assertSame(1, preg_match('~/organisation-invitation/([A-Z0-9]{64})~', $body, $matches));
        if (!isset($matches[1])) {
            self::fail('Invitation message has no fragment token.');
        }
        $claim = $this->call('POST', '/me/organisation-invitations/claim', ['token' => $matches[1]], 2);
        self::assertSame(201, $claim->status, json_encode(self::data($claim), JSON_THROW_ON_ERROR));
        $queued = $this->env->database->selectAll(Query::from('notification_queue')->orderBy('id'));
        self::assertCount(2, $queued);
        self::assertSame(['org_invitation', 'org_invitation'], array_column($queued, 'subject_type'));
        self::assertSame($queued[0]['subject_id'], $queued[1]['subject_id']);
        self::assertSame(1, $this->kernel->notificationSender->drain());
        $proof = $this->env->mailer->sent[1];
        self::assertSame(2, $proof->recipientUserId);
        self::assertSame(1, preg_match('/code is ([A-Z0-9]{12})/', $proof->bodyText ?? '', $code));
        if (!isset($code[1])) {
            self::fail('Proof message has no confirmation code.');
        }
        $accepted = $this->call('POST', '/me/organisation-invitations/accept', [
            'claim_id' => self::data($claim)['claim_id'], 'code' => $code[1],
        ], 2);
        self::assertSame(200, $accepted->status);
        self::assertSame(2, $this->env->database->count(Query::from('org_memberships')));
        self::assertSame(0, $this->env->database->count(Query::from(EncryptedInvitationDelivery::TABLE)));
    }

    public function testRotationAndExpiryPreventOldSecretDelivery(): void
    {
        $this->issue();
        $this->issue(2);
        self::assertSame(1, $this->kernel->notificationSender->drain());
        self::assertCount(1, $this->env->mailer->sent);
        self::assertSame(0, $this->env->database->count(Query::from(EncryptedInvitationDelivery::TABLE)));
        $this->issue(3);
        $this->env->clock->advance('P8D');
        self::assertSame(0, $this->kernel->notificationSender->drain());
        self::assertCount(1, $this->env->mailer->sent);
        self::assertSame(0, $this->env->database->count(Query::from(EncryptedInvitationDelivery::TABLE)));
    }

    public function testSuppressionAndPermanentTransportFailuresPurgeCiphertextAndNeverStoreTransportSecrets(): void
    {
        $this->issue();
        $this->env->mailer->failWith = new \RuntimeException('Rejected member@example.test and CODE1 secret body');
        self::assertSame(0, $this->kernel->notificationSender->drain());
        self::assertSame(0, $this->env->database->count(Query::from(EncryptedInvitationDelivery::TABLE)));
        $queue = json_encode($this->env->database->selectAll(Query::from('notification_queue')), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('member@example.test', $queue);
        self::assertStringNotContainsString('CODE1', $queue);
        $this->env->mailer->failWith = null;
        $this->kernel->notificationSuppressions->suppress('email', NotificationDigest::forAddress('member@example.test'), 'hard_bounce');
        $this->issue(2);
        self::assertSame(0, $this->kernel->notificationSender->drain());
        self::assertCount(0, $this->env->mailer->sent);
        self::assertSame(0, $this->env->database->count(Query::from(EncryptedInvitationDelivery::TABLE)));
    }

    public function testInvitationAndCiphertextRollbackTogetherAndUnavailableHostDoesNotRegisterRoutes(): void
    {
        try {
            $this->env->database->transactional(function (): void {
                $this->issue();
                throw new \RuntimeException('rollback fixture');
            });
        } catch (\RuntimeException $failure) {
            self::assertSame('rollback fixture', $failure->getMessage());
        }
        self::assertSame(0, $this->env->database->count(Query::from('invitations')));
        self::assertSame(0, $this->env->database->count(Query::from('notification_queue')));
        self::assertSame(0, $this->env->database->count(Query::from(EncryptedInvitationDelivery::TABLE)));
        self::assertCount(0, $this->env->mailer->sent);
        $closed = new CoreKernel($this->env->ports);
        self::assertNull($closed->organisationInvitations);
        self::assertSame(404, $closed->apiKernel()->handle(new ApiRequest(
            'POST',
            '/me/organisation-invitations/claim',
            Principal::user(2),
            body: ['token' => str_repeat('A', 64)],
            idempotencyKey: 'closed',
        ))->status);
    }

    private function issue(int $revision = 1): void
    {
        $response = $this->call('POST', '/manage/organisations/' . $this->orgId . '/invitations', [
            'expected_revision' => $revision, 'email' => 'member@example.test', 'role_key' => 'org_member',
        ]);
        self::assertSame(201, $response->status, json_encode(self::data($response), JSON_THROW_ON_ERROR));
    }

    public function testTransientRetryRetainsOnlyCiphertextThenPurgesAfterSuccess(): void
    {
        $this->issue();
        $this->env->mailer->failWith = new \RuntimeException('Connection timeout member@example.test CODE1');
        self::assertSame(0, $this->kernel->notificationSender->drain());
        self::assertSame(1, $this->env->database->count(Query::from(EncryptedInvitationDelivery::TABLE)));
        $queue = json_encode($this->env->database->selectAll(Query::from('notification_queue')), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('member@example.test', $queue);
        self::assertStringNotContainsString('CODE1', $queue);
        $this->env->mailer->failWith = null;
        $this->env->clock->advance('PT1H');
        self::assertSame(1, $this->kernel->notificationSender->drain());
        self::assertCount(1, $this->env->mailer->sent);
        self::assertSame(0, $this->env->database->count(Query::from(EncryptedInvitationDelivery::TABLE)));
    }

    public function testTamperedCipherCannotSendAndExpiryPrunesTheFailedSecret(): void
    {
        $this->issue();
        $this->env->database->update(
            EncryptedInvitationDelivery::TABLE,
            ['sealed_payload' => base64_encode(str_repeat('tampered', 20))],
            ['organisation_id' => $this->orgId]
        );
        self::assertSame(0, $this->kernel->notificationSender->drain());
        self::assertCount(0, $this->env->mailer->sent);
        $queue = json_encode($this->env->database->selectAll(Query::from('notification_queue')), JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('tampered', $queue);
        self::assertStringNotContainsString('member@example.test', $queue);
        $this->env->clock->advance('P8D');
        self::assertSame(0, $this->kernel->notificationSender->drain());
        self::assertSame(0, $this->env->database->count(Query::from(EncryptedInvitationDelivery::TABLE)));
    }

    public function testCorrelatedAuthenticatedComplaintBouncesOnlyTheCurrentInvitationAndRedactsDiagnostics(): void
    {
        $this->issue();
        self::assertSame(1, $this->kernel->notificationSender->drain());
        // Through the host's secret store, because the params bag holds no
        // secret: a write_only field masked the read path and stored the
        // shared secret in cleartext all the same.
        $this->env->secrets->put('env:VIDYALMS_SECRET_VIDYALMS_EMAIL_FEEDBACK_SECRET', 'fixture-feedback');
        $this->kernel->providerSettings->save(
            'Notification',
            'email',
            'site',
            0,
            [],
            null,
            'env:VIDYALMS_SECRET_VIDYALMS_EMAIL_FEEDBACK_SECRET',
        );
        $outcome = $this->kernel->recordDeliveryFeedback->execute(
            'email',
            "Feedback-Type: abuse\r\nUser-Agent: Test/1\r\nVersion: 1\r\n"
                . 'Original-Mail-From: ' . $this->env->mailer->sent[0]->envelopeSender . "\r\n"
                . "Original-Rcpt-To: member@example.test\r\n",
            'message/feedback-report',
            'fixture-feedback'
        );
        self::assertNotNull($outcome['correlated_queue_id']);
        $invitation = $this->env->database->selectOne(Query::from('invitations'));
        self::assertNotNull($invitation);
        self::assertSame('bounced', $invitation['state']);
        self::assertNull($invitation['token_hash']);
        self::assertNull($invitation['pending_key']);
        $organisation = $this->env->database->selectOne(Query::from('organisations'));
        self::assertNotNull($organisation);
        self::assertSame(3, (int) $organisation['revision']);
        self::assertSame(1, $this->env->database->count(Query::from('org_membership_events')
            ->where('event_key', '=', 'invitation.bounced')));
        self::assertSame(0, $this->env->database->count(Query::from(EncryptedInvitationDelivery::TABLE)));
    }

    public function testOldRevisionFeedbackDoesNotCancelNewlyRotatedInvitation(): void
    {
        $this->issue();
        $row = $this->env->database->selectOne(Query::from('notification_queue'));
        self::assertNotNull($row);
        $queued = $this->kernel->notificationQueue->find((int) $row['id']);
        self::assertNotNull($queued);
        $this->issue(2);
        $this->kernel->invitationDelivery->feedback(
            $queued,
            new \Shondalai\VidyaLMS\Core\Registry\Notification\Feedback\DeliveryFeedback('hard_bounce', 'member@example.test')
        );
        $invitation = $this->env->database->selectOne(Query::from('invitations'));
        self::assertNotNull($invitation);
        self::assertSame('pending', $invitation['state']);
        self::assertNotNull($invitation['token_hash']);
    }

    /** @param array<string, mixed> $body */
    private function call(string $method, string $path, array $body, int $actor = 1): ApiResponse
    {
        return $this->kernel->apiKernel()->handle(new ApiRequest(
            $method,
            $path,
            Principal::user($actor),
            body: $body,
            idempotencyKey: 'secure-' . ++$this->sequence,
        ));
    }

    /** @return array<string, mixed> */
    private static function data(ApiResponse $response): array
    {
        self::assertNotNull($response->data);
        return $response->data;
    }
}
