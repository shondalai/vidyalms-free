<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Commerce\SourceRef;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The required authority for the shared writer's organisation-only entry point.
 *
 * Holding one is the proof that both halves of the composed rule passed: the
 * organisation rule and the site owner's release of that course to that
 * organisation. It is the argument type that makes an unscoped organisation
 * grant fail to compile rather than fail in review.
 */
final class OrgEntitlementGrantContext
{
    private function __construct(
        public readonly OrgScope $scope,
        public readonly int $userId,
        public readonly int $membershipId,
        public readonly int $courseId,
        public readonly string $source,
        public readonly string $sourceRef,
        public readonly ?string $expiresAt,
        /**
         * How the authority behind this grant was reached, copied off the
         * scope so the entitlement's own provenance does not have to re-derive
         * it later from rows that may since have changed.
         */
        public readonly string $basis,
        /**
         * The release that let this organisation reach this course.
         *
         * Carried rather than re-read, because the row's own end date already
         * bounded the expiry above and a second read would be the same
         * question asked twice with a window in between.
         *
         * @var array<string, mixed>
         */
        public readonly array $release,
    ) {
    }

    public static function authorise(
        ComposedAccess $access,
        OrganisationStore $store,
        Principal $actor,
        int $organisationId,
        int $userId,
        int $courseId,
        int $organisationRevision,
        ?int $assignmentId = null,
        ?string $expiresAt = null,
    ): self {
        // Both halves, in one call, in the fixed order. It was two calls
        // inlined at three sites before it had a name, which is how one of
        // them ends up with the halves the other way round.
        $grant = $access->require($actor, $organisationId, $courseId, 'learning');
        $scope = $grant->scope;
        $memberId = $store->activeMemberId($scope, $userId);
        if ($memberId === null) {
            throw new ConflictException('Only an active member can receive organisation access.');
        }
        $assignmentRef = null;
        if ($assignmentId !== null) {
            $assignment = $store->database->selectOne(Query::from('seat_assignments', 'a')
                ->select(['a.id', 'a.assignment_ref'])
                ->join('seat_pools', 'p', 'a.pool_id', 'p.id', [['a.organisation_id', 'p.organisation_id']])
                ->where('a.organisation_id', '=', $organisationId)->where('a.id', '=', $assignmentId)
                ->where('a.user_id', '=', $userId)->where('a.membership_id', '=', $memberId)
                ->where('a.state', '=', 'assigned')->whereNull('a.entitlement_id')->where('p.course_id', '=', $courseId));
            if ($assignment === null) {
                throw new ConflictException('The seat grant must bind an ungranted assignment in this organisation.');
            }
            // The reference and not the id, which is the published grammar and
            // the value the design bought the column for. It falls back to the
            // id only for a row written before assignment_ref existed and not
            // yet reached by the upgrade backfill, so a build mid-upgrade
            // still grants rather than refusing.
            $assignmentRef = \is_string($assignment['assignment_ref'] ?? null) && $assignment['assignment_ref'] !== ''
                ? (string) $assignment['assignment_ref'] : (string) $assignmentId;
        }
        $releaseEnds = $grant->endsAt();
        if ($releaseEnds !== null && ($expiresAt === null || $releaseEnds < $expiresAt)) {
            $expiresAt = $releaseEnds;
        }
        $source = $assignmentId === null ? Entitlement::SOURCE_ORG : Entitlement::SOURCE_SEAT;
        // Composed through the shared helper rather than beside it, so the
        // organisation family and the commerce family are one declared
        // vocabulary instead of two grammars discovered in one column. The
        // shape is the shipped one verbatim; only its home moved.
        $sourceRef = $assignmentRef === null
            ? SourceRef::organisationGrant($organisationId, $courseId, $memberId, $organisationRevision)
            : SourceRef::organisationSeat($organisationId, $assignmentRef, $courseId);

        return new self(
            $scope,
            $userId,
            $memberId,
            $courseId,
            $source,
            $sourceRef,
            $expiresAt,
            $scope->basis,
            $grant->release,
        );
    }
}
