<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\RetentionPolicy;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;

/**
 * The one reader of an organisation's own retention policy.
 *
 * Three of the four stored fields were write-only. `access_log_days` reached a
 * caller; `artefact_days`, `record_days` and `statute_ref` reached none, so a
 * per-organisation policy an administrator saved changed nothing at all: the
 * export pipeline stamped every artefact from the site-wide setting, and the
 * erasure receipt's statute references were provider constants rather than the
 * organisation's own. A stored value nothing reads is worse than an absent one,
 * because the console shows it as configured.
 *
 * **The site value is a floor and never a ceiling**, which is the same rule the
 * access log already publishes and for the same reason: an organisation may
 * keep an artefact longer than the site requires and may never keep it for less
 * time than the site decided it must be keepable.
 *
 * It reads through {@see OrganisationStore}, which is the persistence home and
 * applies the tenant predicate, so nothing here composes a query of its own.
 */
final class OrganisationRetentionPolicies
{
    public function __construct(private readonly OrganisationStore $store)
    {
    }

    /** This organisation's stored policy, or the empty one. */
    public function forScope(OrgScope $scope): RetentionPolicy
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
     * How long this organisation's report artefacts live, never below the site
     * floor.
     */
    public function artefactDaysFor(OrgScope $scope, int $siteFloorDays): int
    {
        $declared = $this->forScope($scope)->artefactDays;

        return $declared === null || $declared < $siteFloorDays ? $siteFloorDays : $declared;
    }

    /**
     * The statute an organisation named for the records it keeps, or null.
     *
     * It is the organisation's own reference and it never replaces a provider's
     * statutory basis: a basis says why a record class may be kept at all, and
     * this says which instrument the customer is keeping it under. A receipt
     * carrying the provider's constant where the organisation named its own was
     * answering a regulator with somebody else's citation.
     */
    public function statuteRefFor(OrgScope $scope): ?string
    {
        return $this->forScope($scope)->statuteRef;
    }

    /**
     * How long this organisation keeps a relationship record it is entitled to
     * keep, or null when it has not said.
     *
     * The reader `record_days` did not have. It is reported on the erasure
     * receipt beside the retention basis, so the answer to "for how long" is
     * the organisation's own number rather than a blank.
     */
    public function recordDaysFor(OrgScope $scope): ?int
    {
        return $this->forScope($scope)->recordDays;
    }
}
