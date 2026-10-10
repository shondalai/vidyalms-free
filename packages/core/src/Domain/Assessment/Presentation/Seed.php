<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation;

/**
 * The attempt's presentation seed. Selection, ordering, option shuffle and
 * variable binding all derive from it, so a form is reproducible for
 * support, disputes and regrades, and a reload or a back navigation never
 * reorders mid-attempt.
 *
 * The shuffle itself is deterministic here rather than in a random port,
 * because a shuffle that consults a random source at render time is not
 * reproducible from a stored seed, which is the whole point of storing one.
 */
final class Seed
{
    private function __construct(public readonly string $value)
    {
    }

    public static function of(string $value): self
    {
        return new self($value);
    }

    /**
     * The seed of an attempt that predates the column. Stable per attempt,
     * so an old attempt still re-renders in one fixed order rather than a
     * different one on every read.
     */
    public static function forAttempt(int $attemptId): self
    {
        return new self('attempt-' . $attemptId);
    }

    /**
     * A deterministic permutation of $keys under this seed, scoped to
     * $channel so two fields of one definition do not shuffle identically.
     *
     * Keys listed in $fixed keep their index: QTI's fixed attribute, and
     * without it every "None of the above" item breaks the moment shuffling
     * ships.
     *
     * @param list<string> $keys
     * @param list<string> $fixed
     *
     * @return list<string>
     */
    public function shuffle(string $channel, array $keys, array $fixed = []): array
    {
        $pinned = [];
        $movable = [];

        foreach ($keys as $index => $key) {
            if (\in_array($key, $fixed, true)) {
                $pinned[$index] = $key;
            } else {
                $movable[] = $key;
            }
        }

        usort($movable, fn (string $a, string $b): int => $this->weight($channel, $a) <=> $this->weight($channel, $b) ?: strcmp($a, $b));

        $ordered = [];

        foreach ($keys as $index => $ignored) {
            if (isset($pinned[$index])) {
                $ordered[] = $pinned[$index];

                continue;
            }

            $ordered[] = array_shift($movable) ?? '';
        }

        return $ordered;
    }

    private function weight(string $channel, string $key): string
    {
        return hash('sha256', $this->value . "\0" . $channel . "\0" . $key);
    }
}
