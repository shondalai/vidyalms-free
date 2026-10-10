<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Trigger;

/**
 * One thing a sweep found: who it is about, what it is about, and which
 * occurrence of the schedule it is.
 *
 * The synthetic reference is composed here rather than by each provider,
 * because it is an invariant that every occurrence with no domain event
 * behind it mints one, and an invariant spelled by hand in five providers is
 * four chances to spell it differently.
 *
 * **The cascade depth and the emitting rule id are carried through, and that
 * is loop protection mechanism two.** An automation's `emit_event` stamps the
 * emitting run's depth plus one and the emitting rule id onto the envelope,
 * this object carries both, and a run created from an automation-emitted
 * event inherits the depth instead of starting at the column default. Without
 * the inheritance, a two-rule cycle where rule A emits B's trigger and rule B
 * emits A's presents as a fresh depth-zero run of a different rule every time
 * round, and a counter that resets can never reach a ceiling.
 *
 * Both are trailing and defaulted, so every shipped trigger constructs this
 * exactly as it did: a sweep that knows nothing about automations answers
 * depth zero and no emitting rule, which is the truth about it.
 */
final class TriggerOccurrence
{
    public const SOURCE_SWEEP = 'sweep';
    public const SOURCE_DIGEST = 'digest';

    /**
     * @param array<string, mixed> $parameters
     */
    private function __construct(
        public readonly int $recipientUserId,
        public readonly string $occurrenceKey,
        public readonly string $eventReference,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly array $parameters,
        public readonly ?\DateTimeImmutable $runAt,
        public readonly int $cascadeDepth = 0,
        public readonly ?int $emittingRuleId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function forSweep(
        string $triggerKey,
        int $recipientUserId,
        string $periodBucket,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $parameters = [],
        ?\DateTimeImmutable $runAt = null,
        int $cascadeDepth = 0,
        ?int $emittingRuleId = null,
    ): self {
        $reference = sprintf('%s:%s:%d:%s', self::SOURCE_SWEEP, $triggerKey, $subjectId ?? 0, $periodBucket);

        return new self(
            $recipientUserId,
            $periodBucket,
            $reference,
            $subjectType,
            $subjectId,
            $parameters,
            $runAt,
            $cascadeDepth,
            $emittingRuleId,
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public static function forDigest(
        string $frequency,
        string $categoryKey,
        int $recipientUserId,
        string $periodBucket,
        array $parameters = [],
    ): self {
        $reference = sprintf(
            '%s:%s:%s:%d:%s',
            self::SOURCE_DIGEST,
            $frequency,
            $categoryKey,
            $recipientUserId,
            $periodBucket,
        );

        return new self($recipientUserId, $periodBucket, $reference, null, null, $parameters, null);
    }

    /**
     * The same occurrence, carrying the cascade it was created inside.
     *
     * The engine calls this when an automation-emitted event becomes an
     * occurrence, so the depth and the emitting rule reach the run that is
     * about to be created rather than being looked up afterwards. A trigger
     * provider never calls it: a provider that could set its own depth could
     * reset it, which is the failure the inheritance exists to prevent.
     */
    public function inheritingCascade(int $cascadeDepth, ?int $emittingRuleId): self
    {
        return new self(
            $this->recipientUserId,
            $this->occurrenceKey,
            $this->eventReference,
            $this->subjectType,
            $this->subjectId,
            $this->parameters,
            $this->runAt,
            max(0, $cascadeDepth),
            $emittingRuleId,
        );
    }

    /** Whether this occurrence exists because a rule emitted an event. */
    public function isAutomationEmitted(): bool
    {
        return $this->emittingRuleId !== null;
    }
}
