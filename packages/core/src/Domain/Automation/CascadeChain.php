<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

/**
 * The ordered rule ids already on this cascade.
 *
 * Loop protection mechanism two, and the reason it is a chain rather than a
 * counter. Three mechanisms as first written all failed on the same two-rule
 * cycle: rule A on trigger T1 emitting T2, rule B on trigger T2 emitting T1,
 * both free actions, both at cooldown zero, each cycle presenting as a fresh
 * depth-zero run of a different rule. Mechanism one was same-rule only and
 * could not see it, mechanism two started every event-created run at the
 * column default of zero, and mechanism three was off by default.
 *
 * So the chain is inherited rather than restarted: emit_event stamps the
 * emitting run's depth plus one and the emitting rule id onto the envelope,
 * TriggerOccurrence carries both through, and a run created from an
 * automation-emitted event inherits them. A rule already on the chain is
 * refused before the enqueue, which is what makes the cycle terminate at the
 * second hop rather than at the depth ceiling.
 *
 * It is stored as a comma-separated string in automation_runs.rule_chain,
 * which is a string:190 column, so the chain is bounded and the bound is
 * enforced here rather than by a truncating driver.
 */
final class CascadeChain
{
    /** What automation_runs.rule_chain can hold. */
    public const MAX_LENGTH = 190;

    /**
     * @param list<int> $ruleIds
     */
    private function __construct(public readonly array $ruleIds)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * @param list<int> $ruleIds
     */
    public static function of(array $ruleIds): self
    {
        $clean = [];

        foreach ($ruleIds as $ruleId) {
            if ($ruleId > 0 && !\in_array($ruleId, $clean, true)) {
                $clean[] = $ruleId;
            }
        }

        return new self($clean);
    }

    /** Reads back exactly what toString() wrote, and tolerates a null column. */
    public static function fromString(?string $stored): self
    {
        if ($stored === null || trim($stored) === '') {
            return self::empty();
        }

        $ids = [];

        foreach (explode(',', $stored) as $part) {
            $part = trim($part);

            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return self::of($ids);
    }

    public function contains(int $ruleId): bool
    {
        return \in_array($ruleId, $this->ruleIds, true);
    }

    public function depth(): int
    {
        return \count($this->ruleIds);
    }

    /**
     * The chain with one more rule on it.
     *
     * A rule already on the chain is returned unchanged rather than appended
     * twice, because the chain answers "has this rule already acted on this
     * cascade" and a repeated id would make the answer depend on how many
     * times it was asked.
     */
    public function with(int $ruleId): self
    {
        if ($ruleId < 1 || $this->contains($ruleId)) {
            return $this;
        }

        return new self([...$this->ruleIds, $ruleId]);
    }

    /**
     * The stored form, truncated at a whole id rather than mid-number.
     *
     * A truncated chain loses its oldest entries rather than its newest,
     * because the newest are the ones the next hop is checked against.
     */
    public function toString(): ?string
    {
        if ($this->ruleIds === []) {
            return null;
        }

        $ids = $this->ruleIds;
        $stored = implode(',', $ids);

        while (\strlen($stored) > self::MAX_LENGTH && $ids !== []) {
            array_shift($ids);
            $stored = implode(',', $ids);
        }

        return $stored === '' ? null : $stored;
    }
}
