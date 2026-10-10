<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;

/**
 * One row of the admin access log, before it is chained and written.
 *
 * It is a context object with a private constructor and named factories for
 * the reason the coding standards give: the caller that has a resolved
 * OrgScope and the caller that has only a refused request are two different
 * situations, and an array of eighteen keys lets either of them write the
 * other's row by mistake.
 *
 * The factories are the two an organisation-scoped read can be in.
 * {@see allowed()} is the ordinary case and carries the scope the read ran
 * under, so both organisation anchors, the acting role and the scope digest
 * come from something an authorisation produced. {@see refused()} is the case
 * where no scope exists, which is exactly the attempt ISO/IEC 27001:2022
 * A.8.15 names as an event to log: it carries the organisation the caller
 * asked for as the subject anchor and no actor organisation, because the
 * caller had none.
 */
final class AdminAccessRecord
{
    public const OUTCOME_ALLOWED = 'allowed';
    public const OUTCOME_REFUSED = 'refused';

    /**
     * How wide actor_role_key is, so a truncation is deliberate and marked.
     *
     * It matches the shipped column and is not widened: admin_access_log is
     * already installed on live sites, and an ALTER against an append-only
     * audit table is the one write this design will not make for a
     * presentation nicety. Two ordinary role keys fit; a set that does not
     * keeps whole keys and says how many it dropped, and the whole sorted set
     * is hashed into scope_digest either way.
     */
    public const ROLE_KEY_MAX = 40;

    private function __construct(
        public readonly string $eventType,
        public readonly int $actorId,
        public readonly ?int $actorOrganisationId,
        public readonly ?string $actorRoleKey,
        public readonly ?int $subjectUserId,
        public readonly ?int $subjectOrganisationId,
        public readonly ?string $resource,
        public readonly string $route,
        public readonly string $outcome,
        public readonly int $recordCount,
        public readonly ?string $scopeDigest,
        public readonly ?string $requestRef,
        public readonly ?string $ipHash,
    ) {
    }

    /**
     * A read that ran, under the scope that authorised it.
     *
     * The site-manager path is not an exception and does not get a second
     * factory. A caller holding vidyalms.manage still resolves an OrgScope
     * before reading anything, so their read is recorded here with the same
     * two anchors as a delegated administrator's, which is the whole point:
     * the manage waiver is a rule a site owner can grant from a permissions
     * UI, and a read nobody records is a read nobody can review.
     */
    public static function allowed(
        string $eventType,
        OrgScope $scope,
        string $route,
        int $recordCount,
        ?string $scopeDigest = null,
        ?int $subjectUserId = null,
        ?string $requestRef = null,
        ?string $ipHash = null,
    ): self {
        return new self(
            $eventType,
            $scope->actorId,
            $scope->organisationId,
            self::roleKeys($scope->roles),
            $subjectUserId,
            $scope->organisationId,
            OrganisationAccess::resource($scope->organisationId),
            $route,
            self::OUTCOME_ALLOWED,
            max(0, $recordCount),
            $scopeDigest,
            $requestRef,
            $ipHash,
        );
    }

    /**
     * A read that was refused, which is an event in its own right.
     *
     * There is no actor organisation, because a refusal is precisely the case
     * where the caller had no claim on the one they named. The organisation
     * they asked for is the subject anchor, so the organisation's own log
     * shows the attempt, and the record count is zero because nothing was
     * disclosed.
     */
    public static function refused(
        string $eventType,
        int $actorId,
        ?int $organisationId,
        string $route,
        ?string $requestRef = null,
        ?string $ipHash = null,
    ): self {
        return new self(
            $eventType,
            $actorId,
            null,
            null,
            null,
            $organisationId,
            $organisationId === null ? null : OrganisationAccess::resource($organisationId),
            $route,
            self::OUTCOME_REFUSED,
            0,
            null,
            $requestRef,
            $ipHash,
        );
    }

    /**
     * Every role the caller held, sorted and joined, or null when they held
     * none.
     *
     * It used to be `$scope->roles[0]`, so a caller holding two roles was
     * recorded under whichever sorted first and the other was lost, in the
     * append-only record that exists to answer under what authority a read was
     * made and which by design has no update path. That is the same lossy
     * scalar amendment A8 identified and fixed for role_unit_id.
     *
     * A set too wide for the column keeps whole keys and says how many it
     * dropped, because a silently truncated audit value is worse than a short
     * one that admits it.
     *
     * @param list<string> $roles already sorted and deduplicated by OrgScope
     */
    public static function roleKeys(array $roles): ?string
    {
        if ($roles === []) {
            return null;
        }

        $joined = implode(',', $roles);

        if (\strlen($joined) <= self::ROLE_KEY_MAX) {
            return $joined;
        }

        $kept = [];
        $length = 0;

        foreach ($roles as $index => $role) {
            $suffix = ',+' . (\count($roles) - $index) . ' more';
            $next = $length + ($kept === [] ? 0 : 1) + \strlen($role);

            if ($next + \strlen($suffix) > self::ROLE_KEY_MAX) {
                break;
            }

            $kept[] = $role;
            $length = $next;
        }

        $dropped = '+' . (\count($roles) - \count($kept)) . ' more';

        // Not even one key fits, which means every key is longer than the
        // column. The count is still the honest answer and is still better
        // than a silently cut string.
        return $kept === [] ? $dropped : implode(',', $kept) . ',' . $dropped;
    }

    /**
     * The column shape, without the chain, which the store computes.
     *
     * @return array<string, mixed>
     */
    public function columns(string $occurredAt): array
    {
        return [
            'event_type' => $this->eventType,
            'occurred_at' => $occurredAt,
            'actor_id' => $this->actorId,
            'actor_org_id' => $this->actorOrganisationId,
            'actor_role_key' => $this->actorRoleKey,
            'subject_user_id' => $this->subjectUserId,
            'subject_org_id' => $this->subjectOrganisationId,
            'resource' => $this->resource,
            'route' => $this->route,
            'outcome' => $this->outcome,
            'record_count' => $this->recordCount,
            'scope_digest' => $this->scopeDigest,
            'request_ref' => $this->requestRef,
            'ip_hash' => $this->ipHash,
        ];
    }
}
