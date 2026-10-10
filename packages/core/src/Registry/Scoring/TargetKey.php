<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

/**
 * What one target of a per-target key expects.
 *
 * Two match modes, because the catalogue genuinely needs both and collapsing
 * them silently changes marks. A gap, a zone or a matching row expects one
 * set of values and a superset scores zero for that target, which is what
 * stops the mark-every-word response on a hottext gap being read as
 * containment and paid in full. A blank expects any one of its accepted
 * spellings, which is not set equality at all.
 */
final class TargetKey
{
    public const MATCH_SET = 'set';
    public const MATCH_ANY = 'any';

    /** @var list<string> */
    public readonly array $expected;

    /**
     * @param list<string> $expected
     */
    public function __construct(
        array $expected,
        public readonly string $mode = self::MATCH_SET,
        public readonly bool $caseSensitive = true,
    ) {
        $this->expected = array_values($expected);
    }

    /**
     * @param list<string> $accepted
     */
    public static function anyOf(array $accepted, bool $caseSensitive = false): self
    {
        return new self($accepted, self::MATCH_ANY, $caseSensitive);
    }

    /**
     * @param list<string> $members
     */
    public static function exactly(array $members): self
    {
        return new self($members, self::MATCH_SET, true);
    }

    public function isScored(): bool
    {
        return $this->expected !== [];
    }

    public function matches(mixed $response): bool
    {
        if ($this->mode === self::MATCH_ANY) {
            return $this->matchesAny($response);
        }

        return $this->matchesSet($response);
    }

    private function matchesAny(mixed $response): bool
    {
        if (!\is_string($response)) {
            return false;
        }

        $normalised = $this->normalise($response);

        foreach ($this->expected as $candidate) {
            if ($this->normalise($candidate) === $normalised) {
                return true;
            }
        }

        return false;
    }

    private function matchesSet(mixed $response): bool
    {
        $seen = [];

        if (\is_string($response)) {
            $seen[$this->normalise($response)] = true;
        } elseif (\is_array($response)) {
            foreach ($response as $member) {
                if (\is_string($member)) {
                    $seen[$this->normalise($member)] = true;
                }
            }
        }

        $given = array_keys($seen);
        $wanted = [];

        foreach ($this->expected as $member) {
            $wanted[$this->normalise($member)] = true;
        }

        $wanted = array_keys($wanted);

        sort($given);
        sort($wanted);

        return $given === $wanted;
    }

    private function normalise(string $value): string
    {
        $value = TextNormalisation::collapse($value);

        return $this->caseSensitive ? $value : TextNormalisation::foldCase($value);
    }
}
