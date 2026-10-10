<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * QTI's mapping object: the entries, the value for an unlisted submission,
 * and the raw clamp.
 *
 * These three live here and not in policy.params, because a parameter has
 * exactly one home and the same authored item scoring two different marks
 * depending on which of two locations the engine reads is not a
 * documentation problem.
 */
final class MappingSpec
{
    /** @var array<string, float> */
    public readonly array $entries;

    /**
     * @param array<string, float> $entries
     * @param list<string>         $universe every value the learner may submit
     */
    public function __construct(
        array $entries,
        public readonly float $defaultValue = 0.0,
        public readonly ?float $lowerBound = null,
        public readonly ?float $upperBound = null,
        public readonly int $maxChoices = 0,
        public readonly bool $caseSensitive = false,
        public readonly array $universe = [],
    ) {
        $this->entries = $entries;
    }

    public function valueOf(string $member): float
    {
        if (\array_key_exists($member, $this->entries)) {
            return $this->entries[$member];
        }

        if (!$this->caseSensitive) {
            $folded = TextNormalisation::foldCase($member);

            foreach ($this->entries as $entry => $value) {
                if (TextNormalisation::foldCase($entry) === $folded) {
                    return $value;
                }
            }
        }

        return $this->defaultValue;
    }

    public function isListed(string $member): bool
    {
        if (\array_key_exists($member, $this->entries)) {
            return true;
        }

        if ($this->caseSensitive) {
            return false;
        }

        $folded = TextNormalisation::foldCase($member);

        foreach (array_keys($this->entries) as $entry) {
            if (TextNormalisation::foldCase($entry) === $folded) {
                return true;
            }
        }

        return false;
    }

    /**
     * The maximum a learner could actually collect on this item, not the
     * map's positive total, and the difference is user visible in both
     * directions.
     *
     * A six-region hotspot capped at two choices with four regions mapped at
     * 1.0 has a positive total of 4, so the best response the interface
     * permits scores 2/4 and full marks are unreachable by construction. And
     * the natural way to write "credit every region except the hazardous
     * one", a single entry at minus 1 with default_value 0.5, has a positive
     * total of zero and divides by it.
     */
    public function maxRaw(): float
    {
        $positives = array_values(array_filter($this->entries, static fn (float $value): bool => $value > 0.0));
        rsort($positives);

        if ($this->maxChoices > 0) {
            $positives = \array_slice($positives, 0, $this->maxChoices);
        }

        $max = array_sum($positives);

        if ($this->defaultValue > 0.0) {
            $unlisted = max(0, \count($this->universe) - \count($this->entries));

            if ($this->maxChoices > 0) {
                $unlisted = min($unlisted, max(0, $this->maxChoices - \count($positives)));
            }

            $max += $this->defaultValue * $unlisted;
        }

        if ($this->upperBound !== null) {
            $max = min($max, $this->upperBound);
        }

        return $max;
    }

    /**
     * Refuses a mapping whose attainable maximum is zero or negative,
     * pointing the author at upper_bound as an explicit normaliser.
     */
    public function validate(): void
    {
        if ($this->maxRaw() <= 0.0) {
            throw ValidationException::forField(
                'mapping',
                'This mapping cannot award anything: its attainable maximum is zero or negative. '
                . 'Set upper_bound to normalise it explicitly.',
            );
        }
    }

    public function clampRaw(float $raw): float
    {
        if ($this->lowerBound !== null) {
            $raw = max($this->lowerBound, $raw);
        }

        if ($this->upperBound !== null) {
            $raw = min($this->upperBound, $raw);
        }

        return $raw;
    }
}
