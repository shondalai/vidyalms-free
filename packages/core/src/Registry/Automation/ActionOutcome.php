<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * The answer one action gives the step machine.
 *
 * It carries the before and after state because automation_action_log carries
 * them, and the log is the audit an operator reads when a rule did something
 * nobody expected. An action that reports nothing leaves the two columns null
 * rather than an invented shape.
 *
 * The named factories are the whole vocabulary. An action cannot report a
 * fifth thing, and the four it can report map one to one onto
 * ActionDisposition, so the step machine has no default branch.
 */
final class ActionOutcome
{
    /**
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     */
    private function __construct(
        public readonly ActionDisposition $disposition,
        public readonly array $beforeState,
        public readonly array $afterState,
        public readonly ?string $reason,
        public readonly ?string $message,
        public readonly ?\DateTimeImmutable $nextAttemptAt,
        public readonly bool $retryable,
    ) {
    }

    /**
     * The effect happened. Both states are optional because not every action
     * has a row to describe: notify's effect is a queue row it names in the
     * after state, emit_event's is an occurrence.
     *
     * @param array<string, mixed> $beforeState
     * @param array<string, mixed> $afterState
     */
    public static function executed(array $beforeState = [], array $afterState = []): self
    {
        return new self(ActionDisposition::Executed, $beforeState, $afterState, null, null, null, false);
    }

    /**
     * Nothing was done and nothing was wrong: the guard tree said no, the
     * subject was already in the state the action would have produced, or a
     * policy silenced it. The reason is stored on the queue row, because
     * counting suppressions without their reason cannot tell an operator
     * whether a rule is throttled or simply never applicable.
     *
     * @param array<string, mixed> $beforeState
     */
    public static function suppressed(string $reason, array $beforeState = []): self
    {
        return new self(ActionDisposition::Suppressed, $beforeState, [], $reason, null, null, false);
    }

    /**
     * Not yet. The step returns to pending with next_attempt_at set, its
     * attempt counter untouched, and its idempotency claim released, because
     * a deferral that kept the claim would find its own claim when it came
     * back and short-circuit to "already done".
     */
    public static function deferred(\DateTimeImmutable $nextAttemptAt, string $reason): self
    {
        return new self(ActionDisposition::Deferred, [], [], $reason, null, $nextAttemptAt, false);
    }

    /**
     * It broke. Only this disposition reaches the retry ladder and the
     * failure policy. An action that knows its failure is permanent says so,
     * and the ladder is not consulted.
     *
     * @param array<string, mixed> $beforeState
     */
    public static function failed(
        string $errorCode,
        string $message,
        bool $retryable = true,
        array $beforeState = [],
    ): self {
        return new self(ActionDisposition::Failed, $beforeState, [], $errorCode, $message, null, $retryable);
    }
}
