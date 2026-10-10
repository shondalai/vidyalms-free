<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;

/**
 * What a role decision is answered against: who is asking, where, and what the
 * host actually says about them.
 *
 * It carries no database handle, which is the whole point of the org context
 * rule. A raw handle on the context every org provider receives would let any
 * provider read an org-scoped table with no predicate at all and the compiler
 * would have nothing to say about it.
 *
 * hostCapabilities is the answer AuthorizationPort gave for this person, asked
 * with no resource, and it is the right-hand side of the narrowing
 * intersection: a role's declared capabilities are only ever effective where
 * they appear in this list.
 */
final class OrgRoleContext
{
    /**
     * @param list<string> $roleKeys        the caller's own resolved role keys
     * @param list<string> $hostCapabilities what the host answers for this person
     */
    private function __construct(
        public readonly int $organisationId,
        public readonly ?int $unitId,
        public readonly array $roleKeys,
        public readonly bool $siteManager,
        public readonly array $hostCapabilities,
    ) {
    }

    /**
     * @param list<string> $hostCapabilities
     */
    public static function forCaller(OrgScope $scope, array $hostCapabilities): self
    {
        $capabilities = array_values(array_unique($hostCapabilities));
        sort($capabilities);

        return new self(
            $scope->organisationId,
            $scope->unitId,
            $scope->roles,
            $scope->siteManager,
            $capabilities,
        );
    }
}
