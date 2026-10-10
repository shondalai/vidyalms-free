<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgTenant;
use Shondalai\VidyaLMS\Core\Port\Authorization\AuthorizationPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * What an audience resolver may read: the subject the occurrence is about,
 * the rule's own audience configuration, a database handle, the
 * authorization port for a capability-holder lookup, and the batch size the
 * sender will consume the generator in.
 *
 * The visibility scope is here rather than on the resolver, because a
 * resolver that does not declare FiltersByVisibility is site-wide by
 * definition and the fan-out refuses to use it inside a scoped run.
 */
final class AudienceContext
{
    public const DEFAULT_BATCH_SIZE = 500;

    /**
     * @param array<string, mixed> $config
     */
    private function __construct(
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly ?int $courseId,
        public readonly ?int $learnerUserId,
        public readonly array $config,
        public readonly DatabasePort $database,
        public readonly AuthorizationPort $authorization,
        public readonly ?VisibilityScope $visibilityScope,
        private readonly int $batchSize,
        /**
         * The resolved organisation scope, carried rather than re-derived.
         *
         * Defaulted, so every shipped resolver is untouched. A resolver that
         * reads it declares HonoursOrgScope beside FiltersByVisibility, and an
         * organisation-scoped fan-out refuses one that declares only the
         * older marker: that marker carries no method, so a provider written
         * before organisations existed cannot have honoured a scope kind that
         * did not exist.
         */
        public readonly ?OrgScope $orgScope = null,
        /**
         * The organisation this occurrence is about, when there is no scope.
         *
         * A queued intent records no actor, so the fan-out cannot mint an
         * OrgScope and A16 forbids inventing one. What a use case CAN do, and
         * what the seat threshold alerts do, is stamp the organisation it
         * resolved under its own scope onto the occurrence, and this is that
         * stamp arriving where a resolver can narrow on it.
         *
         * It is beside orgScope rather than instead of it: a caller that has
         * a real scope keeps every guarantee it carries, and {@see tenant()}
         * is what a resolver asks so it does not have to know which of the
         * two it got.
         */
        private readonly ?OrgTenant $stampedTenant = null,
    ) {
    }

    /**
     * The organisation this run is narrowed to, however it arrived.
     *
     * A resolved scope wins, because it carries unit reach a bare stamp does
     * not. Null means a site-wide run, and an organisation audience answers
     * nobody rather than broadcasting, which is the whole tenancy rule.
     */
    public function tenant(): ?OrgTenant
    {
        if ($this->orgScope !== null) {
            return OrgTenant::fromScope($this->orgScope);
        }

        return $this->stampedTenant;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function create(
        DatabasePort $database,
        AuthorizationPort $authorization,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $courseId = null,
        ?int $learnerUserId = null,
        array $config = [],
        ?VisibilityScope $visibilityScope = null,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        ?OrgScope $orgScope = null,
        ?OrgTenant $stampedTenant = null,
    ): self {
        $derived = $visibilityScope;

        if ($derived === null && $orgScope !== null) {
            $derived = VisibilityScope::forOrganisation($orgScope);
        }

        if ($derived === null && $stampedTenant !== null) {
            // A stamped run is an organisational run, so it derives an
            // organisational visibility scope too. That is what makes the
            // fan-out's existing refusal apply to it: a resolver that
            // declares only FiltersByVisibility is refused here exactly as it
            // is on a scoped run, rather than quietly answering site-wide.
            $derived = VisibilityScope::forStampedOrganisation($stampedTenant);
        }

        return new self(
            $subjectType,
            $subjectId,
            $courseId,
            $learnerUserId,
            $config,
            $database,
            $authorization,
            $derived,
            max(1, $batchSize),
            $orgScope,
            $stampedTenant,
        );
    }

    public function batchSize(): int
    {
        return $this->batchSize;
    }
}
