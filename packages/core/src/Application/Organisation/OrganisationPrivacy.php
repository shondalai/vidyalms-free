<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Organisation\RetentionPolicy;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\AccessLogRetentionTask;
use Shondalai\VidyaLMS\Core\Registry\OrgRole\OrgRoleRegistry;
use Shondalai\VidyaLMS\Core\Registry\Privacy\DescribesForArticle30;
use Shondalai\VidyaLMS\Core\Registry\Privacy\EnumeratesOrganisationUsers;
use Shondalai\VidyaLMS\Core\Registry\Privacy\ErasesWithOutcome;
use Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext;
use Shondalai\VidyaLMS\Core\Registry\Privacy\PrivacyContributorRegistry;

/** Organization-owner operations; a host-wide subject request is a different authority. */
final class OrganisationPrivacy
{
    /** The most subjects one catalogue read counts per contributor. */
    public const SUBJECT_COUNT_CAP = 1000;

    public function __construct(
        private readonly OrgScopeResolver $access,
        private readonly OrganisationStore $store,
        private readonly PrivacyContributorRegistry $contributors,
        private readonly OrganisationAccessTermination $termination,
        /**
         * The role registry, for the one question this class asks it: whether
         * the person being erased is the organisation's last owner.
         *
         * It asks the ownership marker rather than a key, so a registered
         * ownership role is protected by the same refusal the free one is.
         * Required and not defaulted: an empty default would answer that
         * nobody owns anything and would quietly drop the refusal, which on an
         * erasure path is a wrong answer with no undo.
         */
        private readonly OrgRoleRegistry $roles,
        /**
         * Where the site-wide access log floor is read from.
         *
         * Trailing and defaulted, so every construction site built before the
         * retention column existed keeps working; a caller with no settings gets
         * the shipped default floor, which is the long answer and therefore the
         * safe one to be wrong with.
         */
        private readonly ?Settings $settings = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function catalogue(Principal $actor, int $orgId): array
    {
        $scope = $this->access->require($actor, $orgId, 'settings');
        $items = [];
        foreach ($this->contributors->all() as $provider) {
            $items[] = ['key' => $provider->key(), 'record_classes' => $provider->describes(),
                // The Article 30 half, present only where a contributor declares
                // the capability. A contributor that does not is recorded as
                // undescribed rather than given an invented purpose, because an
                // invented purpose in a statutory record is worse than a gap an
                // operator can see.
                'article30' => $provider instanceof DescribesForArticle30 ? $provider->article30() : null,
                // The caller EnumeratesOrganisationUsers did not have. It is
                // the set question the interface exists for: how many people
                // inside this organisation this contributor holds rows about,
                // which is not the same number as the membership list because a
                // contributor legitimately holds rows about somebody whose
                // membership has closed. A contributor that cannot answer is
                // reported as null rather than as zero.
                'subjects_in_organisation' => $this->subjectCount($provider, $scope)];
        }
        return ['items' => $items, 'missing_contributors' => $this->contributors->missing(),
            'scope' => 'organization_relationships',
            // No longer false. The column, its write route and its named refusal
            // land together, so the console stops having to say that custom
            // retention is unavailable from the installed providers.
            'retention_policy_available' => true,
            'retention_policy' => $this->retentionPolicy($scope)->toArray(),
            'access_log_floor_days' => $this->accessLogFloorDays()];
    }

    /**
     * Writes this organisation's retention policy, or refuses it by name.
     *
     * **An organisation may lengthen the administrator access log's site floor
     * and may never shorten it.** ISO/IEC 27001:2022 A.8.15 requires that a
     * privileged user cannot interact with the log of the system they control,
     * and an organisation owner shortening their own audit trail is exactly the
     * event that log exists to hold, so the refusal is recorded before it is
     * thrown: the attempt is written to the organisation's own event stream,
     * and the recorded read of this route is written to `admin_access_log` by
     * the middleware in front of it.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function setRetention(Principal $actor, int $orgId, array $input): array
    {
        OrganisationInput::only($input, ['expected_revision', 'retention_policy']);
        $revision = OrganisationInput::integer($input, 'expected_revision');
        $submitted = $input['retention_policy'] ?? null;

        if (!\is_array($submitted)) {
            throw ValidationException::forField('retention_policy', 'Send a retention policy object.');
        }

        $policy = RetentionPolicy::fromInput($submitted);
        $floor = $this->accessLogFloorDays();
        $scope = $this->access->require($actor, $orgId, 'settings');

        try {
            $policy->requireNotBelowFloor($floor);
        } catch (ValidationException $refusal) {
            // **Recorded outside the transaction, then refused.** Recording it
            // inside would be recording nothing: the throw rolls the
            // transaction back and takes the evidence with it, which is the
            // exact failure this write exists to prevent. An organisation owner
            // trying to shorten their own audit trail is the event that trail is
            // for, so the row survives the refusal that follows it.
            $this->store->event(
                $scope,
                (int) $this->store->organisation($scope)['revision'],
                'retention_policy_refused',
                null,
                ['requested_access_log_days' => $policy->accessLogDays, 'site_floor_days' => $floor],
            );

            throw $refusal;
        }

        return $this->store->database->transactional(function () use ($actor, $orgId, $revision, $policy, $floor): array {
            $scope = $this->access->require($actor, $orgId, 'settings');
            $next = $this->store->claim($scope, $revision);
            $this->store->database->update(
                'organisations',
                [
                    'retention_policy' => $policy->isEmpty()
                        ? null
                        : json_encode($policy->toStored(), JSON_THROW_ON_ERROR),
                    'retention_policy_version' => RetentionPolicy::SHAPE,
                ],
                ['id' => $scope->organisationId],
            );
            $this->store->event($scope, $next, 'retention_policy_changed', null, $policy->toArray());

            return [
                'organisation_revision' => $next,
                'retention_policy' => $policy->toArray(),
                'access_log_floor_days' => $floor,
            ];
        });
    }

    /**
     * How many subjects one contributor holds inside this organisation, or
     * null when it cannot say.
     *
     * Counted with a cap, because a contributor over a large tenant yields
     * rather than materialising, and a settings screen must not turn into a
     * full table walk. At the cap the number is reported as the cap, which a
     * console renders as "at least".
     */
    private function subjectCount(object $provider, OrgScope $scope): ?int
    {
        if (!$provider instanceof EnumeratesOrganisationUsers) {
            return null;
        }

        $count = 0;

        foreach ($provider->usersInOrganisation(OrgPrivacyContext::forOrganisation($scope)) as $ignored) {
            $count++;

            if ($count >= self::SUBJECT_COUNT_CAP) {
                break;
            }
        }

        return $count;
    }

    /** This organisation's stored policy, or the empty one. */
    public function retentionPolicy(OrgScope $scope): RetentionPolicy
    {
        $row = $this->store->organisation($scope);
        $stored = $row['retention_policy'] ?? null;
        $decoded = \is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;

        return RetentionPolicy::fromStored(
            \is_array($decoded) ? $decoded : null,
            (int) ($row['retention_policy_version'] ?? RetentionPolicy::SHAPE),
        );
    }

    /**
     * The site-wide floor an organisation policy may only lengthen.
     *
     * Read from the same settings row the sweep reads, through the same
     * clamping rule, so the number a refusal quotes and the number the sweep
     * applies cannot be two different numbers.
     */
    public function accessLogFloorDays(): int
    {
        $stored = $this->settings?->getInt(
            AccessLogRetentionTask::RETENTION_SETTING,
            AccessLogRetentionTask::DEFAULT_RETENTION_DAYS,
        ) ?? AccessLogRetentionTask::DEFAULT_RETENTION_DAYS;

        return $stored < 1 ? AccessLogRetentionTask::DEFAULT_RETENTION_DAYS : $stored;
    }

    /** @return array<string, mixed> */
    public function export(Principal $actor, int $orgId, string $key, string $recordClass, int $page, int $perPage): array
    {
        $scope = $this->access->require($actor, $orgId, 'settings');
        OrganisationInput::page($page, $perPage);
        if (
            preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $key) !== 1
            || preg_match('/^[a-zA-Z0-9_.-]{1,100}$/D', $recordClass) !== 1
        ) {
            throw ValidationException::forField('contributor', 'Choose a valid privacy contributor and record class.');
        }
        $missing = $this->contributors->missing();
        if (!$this->contributors->has($key)) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage,
                'complete' => false, 'missing_contributors' => array_values(array_unique([...$missing, $key]))];
        }
        $data = $this->contributors->get($key)->exportFor(OrgPrivacyContext::forOrganisation($scope), $recordClass, $page, $perPage);
        $this->store->event(
            $scope,
            (int) $this->store->organisation($scope)['revision'],
            'privacy.exported',
            null,
            ['contributor' => $key, 'record_class' => $recordClass, 'page' => $page, 'record_count' => \count($data['items'])]
        );
        return $data + ['page' => $page, 'per_page' => $perPage, 'complete' => $missing === [],
            'missing_contributors' => $missing];
    }

    /** Replays must still find the target inside the caller's current organization. */
    public function requireTarget(Principal $actor, int $orgId, int $memberId): void
    {
        $this->store->member($this->access->require($actor, $orgId, 'settings'), $memberId);
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function erase(Principal $actor, int $orgId, int $memberId, array $input): array
    {
        $this->requireTarget($actor, $orgId, $memberId);
        OrganisationInput::only($input, ['expected_revision', 'acknowledgement']);
        $revision = OrganisationInput::integer($input, 'expected_revision');
        if (($input['acknowledgement'] ?? null) !== 'remove_organization_relationship') {
            throw ValidationException::forField('acknowledgement', 'Confirm removal of this organization relationship.');
        }
        // Resolve every expected provider before the first destructive write.
        if ($this->contributors->missing() !== []) {
            throw new ConflictException(
                'Erasure stopped because a privacy contributor is unavailable.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                ['missing_contributors' => $this->contributors->missing()]
            );
        }
        return $this->store->database->transactional(function () use ($actor, $orgId, $memberId, $revision): array {
            $scope = $this->access->require($actor, $orgId, 'settings');
            $member = $this->store->member($scope, $memberId);
            $userId = (int) $member['user_id'];
            if ($userId < 1) {
                throw new ConflictException('This organization identity link has already been removed.');
            }
            $subjectRows = \Shondalai\VidyaLMS\Core\Port\Database\Query::from('org_memberships')
                ->where('organisation_id', '=', $orgId)->where('user_id', '=', $userId);
            if ($this->store->database->count((clone $subjectRows)->where('directory_managed', '=', 1)) > 0) {
                throw new ConflictException('Disconnect this membership from its provisioning source before erasure.');
            }
            if (
                $this->roles->holdsOwnership($this->store->activeRoles($orgId, $userId))
                && $this->store->activeOwnerCount($scope, $this->roles->ownershipKeys()) <= 1
            ) {
                throw new ConflictException('Assign another active owner before erasing the last owner.');
            }
            $this->termination->requireSettled($scope, userId: $userId);
            $next = $this->store->claim($scope, $revision);
            // Historical membership ids can address the same person. Close every
            // current relationship in this tenant, not merely the selected old row.
            $this->store->database->update(
                'org_memberships',
                [
                'state' => 'left', 'active_key' => null, 'left_at' => $this->store->now(), 'updated_at' => $this->store->now()],
                ['organisation_id' => $orgId, 'user_id' => $userId]
            );
            $cleanup = $this->termination->execute($scope, $next, 'org_privacy_erasure', userId: $userId);
            if ($cleanup !== null) {
                $this->store->event($scope, $next, 'privacy.cleanup_queued', $userId, ['membership_id' => $memberId]);
                return ['state' => 'cleanup_queued', 'organisation_revision' => $next, 'erasure_complete' => false,
                    'cleanup' => $cleanup, 'receipts' => null,
                    'next_action' => 'After access cleanup completes, reload the organization revision and submit a new erasure request.'];
            }
            $receipts = [];
            $unjustified = [];
            foreach ($this->contributors->all() as $contributor) {
                $context = OrgPrivacyContext::forSubject($scope, $userId);

                // The typed receipt where a contributor can answer one, which is
                // what makes the retention basis a value the route can read
                // rather than a key in an array nobody inspects. Without the
                // probe, ErasesWithOutcome was implemented by two free
                // providers and asked by nothing, and the receipt invariant
                // that a kept record class names its basis was enforced only in
                // a test.
                if ($contributor instanceof ErasesWithOutcome) {
                    $outcome = $contributor->eraseWithOutcome($context);
                    $receipts[$contributor->key()] = $outcome->toArray();

                    foreach ($outcome->unjustified() as $recordClass) {
                        $unjustified[] = $contributor->key() . '.' . $recordClass;
                    }

                    continue;
                }

                $receipts[$contributor->key()] = $contributor->eraseFor($context);
            }

            if ($unjustified !== []) {
                // Warned rather than refused, because the rows are already
                // erased by the time the receipt is read and refusing here
                // would leave the erasure half done. The route says which
                // record classes were kept with no basis so the answer is on
                // the receipt rather than in a reviewer's head.
                sort($unjustified);
                $this->store->event($scope, $next, 'privacy.retention_unjustified', null, [
                    'record_classes' => $unjustified,
                ]);
            }
            // Audit only the surviving membership record id; do not reintroduce the removed subject link.
            $this->store->event($scope, $next, 'privacy.relationship_erased', null, ['membership_id' => $memberId]);
            return ['state' => 'relationship_erased', 'organisation_revision' => $next, 'erasure_complete' => false,
                'receipts' => $receipts, 'next_action' => 'Review retained ledger records with the site privacy administrator.'];
        });
    }
}
