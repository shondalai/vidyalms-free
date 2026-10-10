<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * What an AI policy may read while it decides.
 *
 * A policy holds the Principal, unlike a task, because a ceiling is a
 * statement about a person or an organisation and a policy that could not
 * name the caller could not enforce one. It holds no payload and no bridge:
 * a policy that could read the segments would be a second redactor, and one
 * that could call a model would be a second caller.
 *
 * The settings resolver is a closure rather than a repository so that a
 * third-party policy reads scoped params through the one shipped resolution
 * order without being handed the table.
 */
final class AiDecisionContext
{
    /** @var \Closure(string, mixed): mixed */
    private readonly \Closure $settings;

    /**
     * @param \Closure(string, mixed): mixed $settings scoped params lookup, already resolved to this scope
     */
    private function __construct(
        public readonly \DateTimeImmutable $now,
        public readonly Principal $principal,
        public readonly string $scopeType,
        public readonly ?int $scopeId,
        public readonly CostRecord $spentInWindow,
        public readonly int $policyVersion,
        \Closure $settings,
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
        $this->settings = $settings;
    }

    /**
     * @param \Closure(string, mixed): mixed|null $settings
     */
    public static function create(
        \DateTimeImmutable $now,
        Principal $principal,
        string $scopeType = 'site',
        ?int $scopeId = null,
        ?CostRecord $spentInWindow = null,
        int $policyVersion = 1,
        ?\Closure $settings = null,
        ?OrgScope $orgScope = null,
    ): self {
        return new self(
            $now,
            $principal,
            $scopeType,
            $scopeId,
            $spentInWindow ?? CostRecord::none(),
            $policyVersion,
            $settings ?? static fn (string $key, mixed $default = null): mixed => $default,
            $orgScope,
        );
    }

    /** One scoped setting, resolved through the shipped scope order. */
    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings)($key, $default);
    }
}
