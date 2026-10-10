<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

/**
 * What an over-allocation provider decided.
 *
 * Three outcomes and no more, because the field has exactly three defensible
 * stances: Microsoft Entra refuses and records a durable, listed, retryable
 * row; a Microsoft Enterprise Agreement permits deployment beyond the
 * baseline and reconciles later; LearnDash silently raises the pool. The free
 * build ships the first. The other two are Business, and they are named here
 * so a Business provider is a registration rather than a core edit.
 *
 * A value object rather than a string, because `expand_pool` has to say by
 * how much and `allow_and_flag` has to say that the row is over the baseline,
 * and a string answer would have made both a second lookup.
 */
final class SeatOverAllocationOutcome
{
    /** Refuse the seat and keep the person as a durable, inspectable row. */
    public const QUEUE = 'queue';

    /** Seat them and mark the pool over-allocated pending a true-up. */
    public const ALLOW_AND_FLAG = 'allow_and_flag';

    /** Raise the funded quantity, which must emit pool_quantity_increased with the actor. */
    public const EXPAND_POOL = 'expand_pool';

    /** Seat them normally; the pool had room. */
    public const SEAT = 'seat';

    private function __construct(
        public readonly string $decision,
        public readonly ?string $problemCode,
        public readonly int $expandBy,
    ) {
    }

    /** The pool had room, so nothing about it is remarkable. */
    public static function seat(): self
    {
        return new self(self::SEAT, null, 0);
    }

    /**
     * Refused and recorded.
     *
     * The problem code is Entra's own word by default, because an operator
     * reading it beside a Microsoft licence report should see one vocabulary
     * rather than two.
     */
    public static function queue(string $problemCode = 'CountViolation'): self
    {
        return new self(self::QUEUE, $problemCode, 0);
    }

    public static function allowAndFlag(string $problemCode = 'OverBaseline'): self
    {
        return new self(self::ALLOW_AND_FLAG, $problemCode, 0);
    }

    public static function expandPool(int $by): self
    {
        if ($by < 1) {
            throw new \InvalidArgumentException('An expansion must add at least one seat.');
        }

        return new self(self::EXPAND_POOL, null, $by);
    }

    /** Whether the assignment gets a seat now, under any of the three permitting stances. */
    public function seats(): bool
    {
        return $this->decision !== self::QUEUE;
    }
}
