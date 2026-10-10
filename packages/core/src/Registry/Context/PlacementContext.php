<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * What a placement may read while it decides whether to draw and what to
 * offer.
 *
 * The permissions are resolved before the placement is asked, and handed in
 * as the list that resolved, rather than the placement being given an
 * authorisation handle. Permission is per (placement, task) pair, so a
 * placement holding the gate would be asked to decide about tasks it does not
 * know exist, and a third-party placement holding it would be free to skip
 * the question entirely.
 */
final class PlacementContext
{
    /** @var list<string> */
    public readonly array $permissions;

    /**
     * @param list<string> $permissions the `ai.<placement>.<task>` grants that resolved for this caller
     */
    private function __construct(
        public readonly Principal $principal,
        public readonly string $surfaceKey,
        public readonly string $anchor,
        public readonly string $scopeType,
        public readonly ?int $scopeId,
        public readonly string $locale,
        array $permissions,
        /**
         * The resolved organisation scope, carried rather than re-derived.
         *
         * R5 landed the type and the rule it carries: a scope is minted only
         * after the resolver's check, so a run raised inside an organisation
         * carries the authorisation that raised it and the per-organisation
         * reads narrow on the scope rather than on a number a caller supplied.
         * Null is the site, which is what a run started from the site console
         * is, and not a missing tenant.
         *
         * A provider that actually reads it declares HonoursOrgScope, and an
         * organisation-scoped run refuses one that does not: a provider written
         * before organisations existed must not inherit a promise about a scope
         * kind that did not exist when it was written.
         */
        public readonly ?OrgScope $orgScope = null,
    ) {
        $this->permissions = $permissions;
    }

    /**
     * @param list<string> $permissions
     */
    public static function create(
        Principal $principal,
        string $surfaceKey,
        string $anchor,
        string $scopeType = 'site',
        ?int $scopeId = null,
        string $locale = 'en-GB',
        array $permissions = [],
        ?OrgScope $orgScope = null,
    ): self {
        return new self(
            $principal,
            $surfaceKey,
            $anchor,
            $scopeType,
            $scopeId,
            $locale,
            $permissions,
            $orgScope,
        );
    }

    public function permits(string $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }
}
