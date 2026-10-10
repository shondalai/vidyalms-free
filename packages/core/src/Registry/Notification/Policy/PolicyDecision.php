<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Policy;

/**
 * What one policy said about one message.
 *
 * Policies veto or defer, they never select. A deny is terminal and writes
 * suppressed_reason; a defer is not terminal and writes next_attempt_at.
 * That distinction is not decoration: quiet hours is one of the eight and its
 * required behaviour is "not now, at 08:00", which a deny-set cannot express,
 * so registering it as a veto-only policy turns every quiet-hours message
 * into a silent suppression. A certificate mail landing at 23:40 inside a
 * 22:00 to 07:00 window must be delivered late, not dropped.
 *
 * A policy that could also select a channel would have to know the channel
 * catalogue, which is exactly the coupling being avoided.
 *
 * **The map key is a target key, and for a message that target is its
 * channel.** Two later producers decide with this same object and neither has
 * a channel: an automation step and an AI run. Forking a second decision
 * object for them would give the product two vocabularies for one fold, and
 * an admin screen rendering deny and defer for mail and for a model run would
 * be reading a member called channel in one and something else in the other.
 * So the convention is published here rather than invented per caller: a
 * target that has a channel is keyed by its channel key, exactly as it always
 * was, and a target that has none is keyed by the single constant naming its
 * kind. TARGET_AUTOMATION_STEP is one automation step, TARGET_AI_RUN is one
 * AI run.
 *
 * One key and not a per-step or per-run key, because the decision is about
 * the step or the run in front of the policy: a policy answering about two of
 * them at once would be a policy that had been handed two, which no caller
 * does. The fold is then unchanged and needs no special case, because a deny
 * beating a defer on the same key is the same rule whether the key is `email`
 * or `automation.step`.
 */
final class PolicyDecision
{
    /**
     * The target key an automation step's policies decide under. It has no
     * channel: the step is the thing being allowed, deferred or denied.
     */
    public const TARGET_AUTOMATION_STEP = 'automation.step';

    /** The target key an AI run's policies decide under, for the same reason. */
    public const TARGET_AI_RUN = 'ai.run';

    /**
     * @param array<string, string>              $denied   target key to machine-readable reason
     * @param array<string, \DateTimeImmutable>  $deferred target key to the instant to try again
     */
    private function __construct(
        public readonly array $denied,
        public readonly array $deferred,
    ) {
    }

    public static function allow(): self
    {
        return new self([], []);
    }

    /**
     * The parameter keeps its shipped name. For a message a target key is a
     * channel key, which is what it has always been; for a channel-less
     * target it is TARGET_AUTOMATION_STEP or TARGET_AI_RUN.
     *
     * @param list<string> $channelKeys target keys
     */
    public static function deny(array $channelKeys, string $reason): self
    {
        $denied = [];

        foreach ($channelKeys as $channelKey) {
            $denied[$channelKey] = $reason;
        }

        return new self($denied, []);
    }

    /**
     * The parameter keeps its shipped name, and holds target keys on the
     * same convention deny() states.
     *
     * @param list<string> $channelKeys target keys
     */
    public static function defer(array $channelKeys, \DateTimeImmutable $until): self
    {
        $deferred = [];

        foreach ($channelKeys as $channelKey) {
            $deferred[$channelKey] = $until;
        }

        return new self([], $deferred);
    }

    public function isEmpty(): bool
    {
        return $this->denied === [] && $this->deferred === [];
    }

    /**
     * The fold: a deny anywhere wins over a defer for the same channel,
     * because a terminal answer cannot be undone by a later one, and the
     * latest deferral wins among deferrals so a message never moves earlier.
     */
    public function merge(self $other): self
    {
        $denied = $this->denied + $other->denied;
        $deferred = $this->deferred;

        foreach ($other->deferred as $channelKey => $until) {
            if (!isset($deferred[$channelKey]) || $until > $deferred[$channelKey]) {
                $deferred[$channelKey] = $until;
            }
        }

        foreach (array_keys($denied) as $channelKey) {
            unset($deferred[$channelKey]);
        }

        return new self($denied, $deferred);
    }
}
