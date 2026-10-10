<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * What a fact provider may read while it resolves one fact.
 *
 * This is the context that carries a database handle, and it is the reason
 * AiTaskContext does not have to. A fact is a read, resolved once by a
 * registered provider under the resolving Principal, and its value is then
 * handed to the task as a value. That split is what makes minimisation the
 * default: the task never holds the handle, so it can never read a fact it
 * did not declare.
 *
 * A third-party fact reading its own package's tables needs this for the same
 * reason a third-party trigger does: a registry cannot supply a constructor
 * it does not know about.
 */
final class AiFactContext
{
    private function __construct(
        public readonly Principal $principal,
        public readonly DatabasePort $database,
        public readonly \DateTimeImmutable $now,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly string $scopeType,
        public readonly ?int $scopeId,
        public readonly string $locale,
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
    }

    public static function create(
        Principal $principal,
        DatabasePort $database,
        \DateTimeImmutable $now,
        ?string $subjectType = null,
        ?int $subjectId = null,
        string $scopeType = 'site',
        ?int $scopeId = null,
        string $locale = 'en-GB',
        ?OrgScope $orgScope = null,
    ): self {
        return new self(
            $principal,
            $database,
            $now,
            $subjectType,
            $subjectId,
            $scopeType,
            $scopeId,
            $locale,
            $orgScope,
        );
    }
}
