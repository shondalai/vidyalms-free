<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access\Availability;

/**
 * What a leaf, a node or a whole tree answered.
 *
 * opensAt is the field that makes everything else cheap. A time-based
 * condition that can name the instant it will open feeds the learner line,
 * the scheduled unlock notification's runAt, the blocked-learners report and
 * the "what unlocks next" widget from one evaluation, which is why there is
 * no drip calculator anywhere in this codebase.
 *
 * exhausted is the fourth denominator case, and it exists because a closed
 * window is neither NEVER nor satisfiable. A date leaf past its "until" is
 * time-like, so it may not return NEVER; under a plain count-blocked rule
 * that item would stay counted and permanently uncompletable, and an
 * instructor closing a week-3 assignment would leave every enrolment one
 * item short of all_lessons forever. Only the leaf may set it, it is never
 * derivable from the capability set, and it is computed on every read and
 * never cached, which is what keeps it correct while the NEVER set stays
 * cacheable.
 *
 * unlockAction is present from the first release and unused by the free
 * build: a condition satisfied by a learner action rather than by a read
 * cannot be hosted by an interface that assumes every condition is a pure
 * read, and adding the field afterwards is a breaking change to a published
 * value object.
 */
final class AvailabilityOutcome
{
    /**
     * @param array<string, mixed>      $reasonParameters
     * @param array<string, mixed>|null $unlockAction
     */
    private function __construct(
        public readonly AvailabilityState $state,
        public readonly ?string $reasonKey = null,
        public readonly array $reasonParameters = [],
        public readonly ?\DateTimeImmutable $opensAt = null,
        public readonly ?string $opensAtTimezone = null,
        public readonly bool $exhausted = false,
        public readonly ?array $unlockAction = null,
    ) {
    }

    public static function available(): self
    {
        return new self(AvailabilityState::Available);
    }

    /**
     * @param array<string, mixed>      $reasonParameters
     * @param array<string, mixed>|null $unlockAction
     */
    public static function blocked(
        string $reasonKey,
        array $reasonParameters = [],
        ?\DateTimeImmutable $opensAt = null,
        ?string $opensAtTimezone = null,
        bool $exhausted = false,
        ?array $unlockAction = null,
    ): self {
        return new self(
            AvailabilityState::Blocked,
            $reasonKey,
            $reasonParameters,
            $opensAt,
            $opensAtTimezone,
            $exhausted,
            $unlockAction,
        );
    }

    /**
     * @param array<string, mixed> $reasonParameters
     */
    public static function never(string $reasonKey, array $reasonParameters = []): self
    {
        return new self(AvailabilityState::Never, $reasonKey, $reasonParameters);
    }

    public function isAvailable(): bool
    {
        return $this->state === AvailabilityState::Available;
    }

    public function isNever(): bool
    {
        return $this->state === AvailabilityState::Never;
    }

    public function isBlocked(): bool
    {
        return $this->state === AvailabilityState::Blocked;
    }

    /**
     * The denominator rule, stated once so no caller re-derives it: count
     * items whose outcome is AVAILABLE, or BLOCKED with exhausted false.
     * Exclude NEVER, and exclude BLOCKED with exhausted true.
     */
    public function countsTowardsCompletion(): bool
    {
        return match ($this->state) {
            AvailabilityState::Available => true,
            AvailabilityState::Blocked => !$this->exhausted,
            AvailabilityState::Never => false,
        };
    }

    /**
     * The same outcome with exhaustion stripped. The composer applies it to
     * a leaf whose provider does not declare DeclaresExhaustion, exactly as
     * an undeclared NEVER is downgraded.
     */
    public function withoutExhaustion(): self
    {
        if (!$this->exhausted) {
            return $this;
        }

        return new self(
            $this->state,
            $this->reasonKey,
            $this->reasonParameters,
            $this->opensAt,
            $this->opensAtTimezone,
            false,
            $this->unlockAction,
        );
    }

    /**
     * A NEVER read as BLOCKED, which is what a provider that does not
     * implement FiltersLearners gets. A silently corrupted completion
     * denominator is the one failure in this design that is invisible until
     * credentials are wrong.
     */
    public function downgradedToBlocked(): self
    {
        if ($this->state !== AvailabilityState::Never) {
            return $this;
        }

        return new self(
            AvailabilityState::Blocked,
            $this->reasonKey,
            $this->reasonParameters,
            null,
            null,
            false,
            $this->unlockAction,
        );
    }

    /**
     * The learner-facing access block, which carries neither NEVER nor
     * exhausted.
     *
     * @return array{state: string, reason: string|null, reason_parameters: array<string, mixed>,
     *               opens_at: string|null, opens_at_timezone: string|null}
     */
    public function toLearnerArray(): array
    {
        return [
            'state' => $this->state->learnerFacing(),
            'reason' => $this->isAvailable() ? null : $this->reasonKey,
            'reason_parameters' => $this->isAvailable() ? [] : $this->reasonParameters,
            'opens_at' => $this->opensAt?->format(\DateTimeInterface::ATOM),
            'opens_at_timezone' => $this->opensAtTimezone,
        ];
    }

    /**
     * The author-facing outcome, where never and exhausted are visible.
     *
     * @return array<string, mixed>
     */
    public function toAuthorArray(): array
    {
        return [
            'state' => $this->state->value,
            'exhausted' => $this->exhausted,
            'counts_towards_completion' => $this->countsTowardsCompletion(),
            'reason' => $this->reasonKey,
            'reason_parameters' => $this->reasonParameters,
            'opens_at' => $this->opensAt?->format(\DateTimeInterface::ATOM),
            'opens_at_timezone' => $this->opensAtTimezone,
            'unlock_action' => $this->unlockAction,
        ];
    }
}
