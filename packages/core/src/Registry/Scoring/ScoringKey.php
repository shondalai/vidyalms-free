<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

/**
 * What the authored definition says the answer is worth, translated out of
 * the type's own field names into the vocabulary policies share.
 *
 * A type builds one of these from its definition and hands it to whichever
 * policy the item names. That is the whole separation: adding
 * right-minus-wrong to matching becomes registering a policy rather than
 * editing matching, and a third-party type gets every policy without
 * reimplementing one.
 */
final class ScoringKey
{
    /** @var list<string> */
    public readonly array $members;

    /** @var array<string, TargetKey> */
    public readonly array $targets;

    /** @var array<string, float> */
    public readonly array $mapping;

    /** @var list<string> */
    public readonly array $universe;

    /** @var list<TextMatcher> */
    public readonly array $matchers;

    /**
     * @param list<string>             $members  the keyed set or sequence
     * @param array<string, TargetKey> $targets  per-target expectations
     * @param array<string, float>     $mapping  QTI's mapping entries
     * @param list<string>             $universe every value the learner could submit
     * @param list<TextMatcher>        $matchers an ordered matcher list, first match wins
     */
    private function __construct(
        public readonly mixed $value = null,
        array $members = [],
        array $targets = [],
        array $mapping = [],
        array $universe = [],
        array $matchers = [],
        public readonly ?MappingSpec $mappingSpec = null,
    ) {
        $this->members = array_values($members);
        $this->targets = $targets;
        $this->mapping = $mapping;
        $this->universe = array_values($universe);
        $this->matchers = array_values($matchers);
    }

    public static function value(mixed $value): self
    {
        return new self(value: $value);
    }

    /**
     * @param list<string> $members
     * @param list<string> $universe
     */
    public static function set(array $members, array $universe = []): self
    {
        return new self(members: $members, universe: $universe);
    }

    /**
     * @param list<string> $sequence
     * @param list<string> $universe
     */
    public static function sequence(array $sequence, array $universe = []): self
    {
        return new self(value: $sequence, members: $sequence, universe: $universe === [] ? $sequence : $universe);
    }

    /**
     * @param array<string, TargetKey> $targets
     */
    public static function targets(array $targets): self
    {
        return new self(targets: $targets);
    }

    public static function mapped(MappingSpec $spec): self
    {
        return new self(mapping: $spec->entries, universe: $spec->universe, mappingSpec: $spec);
    }

    /**
     * @param list<TextMatcher> $matchers
     */
    public static function matchers(array $matchers): self
    {
        return new self(matchers: $matchers);
    }

    /**
     * The number of distractors: values the learner could submit that carry
     * no credit. guess_neutral's penalty weight is |K| / |D| and rejects
     * |D| = 0 as degenerate, so the count has to be a real number rather
     * than an inference from the response.
     */
    public function distractorCount(): int
    {
        return max(0, \count($this->universe) - \count(array_unique($this->members)));
    }

    /**
     * Only the targets that carry a key. An unkeyed target leaves the
     * denominator as well as the numerator: where the denominator was the
     * target count, an image-labelling item with five zones and three keyed
     * capped a perfect answer at 0.6.
     *
     * @return array<string, TargetKey>
     */
    public function scoredTargets(): array
    {
        return array_filter($this->targets, static fn (TargetKey $target): bool => $target->isScored());
    }

    public function isEmpty(): bool
    {
        return $this->value === null
            && $this->members === []
            && $this->targets === []
            && $this->mapping === []
            && $this->matchers === [];
    }
}
