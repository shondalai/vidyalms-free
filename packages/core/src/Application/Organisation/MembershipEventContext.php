<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent;

/**
 * The columns beside the event key: where it happened, what it was about, who
 * caused it, and the two clocks.
 *
 * A context object with a private constructor and named factories, on the
 * standing terms: the console, a roster load and a provisioning source write
 * the same nine event keys and mean three different provenances by them, and a
 * positional argument list they all share is one any of them can fill wrongly
 * without the type system noticing. Each caller names its own factory, and the
 * factory is what fixes `source` and `actor_type` together, because those two
 * are the pair that must never disagree: a row saying `source = roster` and
 * `actor_type = principal` is a row claiming a person typed a CSV.
 *
 * ## The two clocks
 *
 * `occurred_at` is when the thing happened in the world and `recorded_at` is
 * when the row was written. They are equal for a console edit and they are not
 * for anything else: a OneRoster delta naming a `dateLastModified` of last
 * Tuesday happened last Tuesday, and a report that cannot tell a backdated
 * correction from a delayed webhook cannot answer an audit. A caller that does
 * not know when something happened leaves `occurredAt` null and the store
 * stamps both from the clock, which is honest: it says "as far as this system
 * knows, now".
 *
 * ## What is deliberately not here
 *
 * The details payload. It stays the free-form array `event()` has always taken,
 * because it is provider shape under a version column and this object is the
 * fixed columns. Mixing the two would put a payload key in competition with a
 * column, which is the one thing the schema notes on this table forbid.
 */
final class MembershipEventContext
{
    private function __construct(
        public readonly string $source,
        public readonly string $actorType,
        public readonly ?int $orgUnitId = null,
        public readonly ?string $roleKey = null,
        public readonly ?string $sourceRef = null,
        public readonly ?string $decisionRef = null,
        public readonly ?string $occurredAt = null,
    ) {
    }

    /** A person acting in the console, now. */
    public static function manual(
        ?int $orgUnitId = null,
        ?string $roleKey = null,
        ?string $sourceRef = null,
    ): self {
        return new self(
            MembershipEvent::SOURCE_MANUAL,
            MembershipEvent::ACTOR_PRINCIPAL,
            $orgUnitId,
            $roleKey,
            $sourceRef,
        );
    }

    /**
     * A roster file an operator uploaded.
     *
     * `sourceRef` is the foreign natural key the row carried, which is a
     * OneRoster sourcedId or the file's own external id, and never an email
     * address: an address is reassignable and an audit that keys on one cannot
     * be replayed.
     */
    public static function roster(
        string $sourceRef,
        ?int $orgUnitId = null,
        ?string $roleKey = null,
        ?string $occurredAt = null,
    ): self {
        return new self(
            MembershipEvent::SOURCE_ROSTER,
            MembershipEvent::ACTOR_IMPORTER,
            $orgUnitId,
            $roleKey,
            $sourceRef,
            null,
            $occurredAt,
        );
    }

    /**
     * A provisioning source asserting continuously.
     *
     * `decisionRef` is the MappingDecision this membership was the effect of,
     * so the question "why did this person have access on 3 March" has an
     * explainer to point at rather than a rule set that has since changed.
     */
    public static function directory(
        string $sourceRef,
        ?string $decisionRef = null,
        ?int $orgUnitId = null,
        ?string $roleKey = null,
        ?string $occurredAt = null,
    ): self {
        return new self(
            MembershipEvent::SOURCE_DIRECTORY,
            MembershipEvent::ACTOR_IMPORTER,
            $orgUnitId,
            $roleKey,
            $sourceRef,
            $decisionRef,
            $occurredAt,
        );
    }

    /** An invitation the person accepted. */
    public static function invitation(string $sourceRef, ?string $roleKey = null): self
    {
        return new self(
            MembershipEvent::SOURCE_INVITATION,
            MembershipEvent::ACTOR_PRINCIPAL,
            null,
            $roleKey,
            $sourceRef,
        );
    }

    /** The product itself: a sweep, an expiry, a scheduled task. */
    public static function system(?string $sourceRef = null, ?int $orgUnitId = null): self
    {
        return new self(
            MembershipEvent::SOURCE_SYSTEM,
            MembershipEvent::ACTOR_SYSTEM,
            $orgUnitId,
            null,
            $sourceRef,
        );
    }

    /** The same context with a role named, for the caller writing two rows about one request. */
    public function forRole(string $roleKey): self
    {
        return new self(
            $this->source,
            $this->actorType,
            $this->orgUnitId,
            $roleKey,
            $this->sourceRef,
            $this->decisionRef,
            $this->occurredAt,
        );
    }

    /** The same context in a named unit. */
    public function inUnit(?int $orgUnitId): self
    {
        return new self(
            $this->source,
            $this->actorType,
            $orgUnitId,
            $this->roleKey,
            $this->sourceRef,
            $this->decisionRef,
            $this->occurredAt,
        );
    }

    /**
     * The columns this context contributes, with the recording instant the
     * store supplies.
     *
     * `occurred_at` falls back to `recorded_at` rather than to null, because a
     * null there means "written before the two clocks existed" and a row this
     * object wrote is not one of those.
     *
     * @return array<string, mixed>
     */
    public function columns(string $recordedAt): array
    {
        return [
            'org_unit_id' => $this->orgUnitId,
            'role_key' => $this->roleKey,
            'source' => $this->source,
            'source_ref' => $this->sourceRef,
            'actor_type' => $this->actorType,
            'decision_ref' => $this->decisionRef,
            'occurred_at' => $this->occurredAt ?? $recordedAt,
            'recorded_at' => $recordedAt,
        ];
    }
}
