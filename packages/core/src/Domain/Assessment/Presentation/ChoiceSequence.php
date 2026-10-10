<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation;

/**
 * QTI's choiceSequence: the order the learner actually saw, per shuffled
 * field of the definition.
 *
 * Persisted on attempt_questions. Without it a graded attempt cannot be
 * re-rendered as the learner saw it, which breaks review, appeals and item
 * analysis.
 */
final class ChoiceSequence
{
    /** @var array<string, list<string>> */
    public readonly array $sequences;

    /**
     * @param array<string, list<string>> $sequences field name to ordered keys
     */
    public function __construct(array $sequences = [])
    {
        $this->sequences = $sequences;
    }

    public static function empty(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->sequences === [];
    }

    /**
     * @return list<string>
     */
    public function for(string $field): array
    {
        return $this->sequences[$field] ?? [];
    }

    /**
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        return $this->sequences;
    }

    /**
     * @param array<string, mixed>|null $stored
     */
    public static function fromArray(?array $stored): self
    {
        if ($stored === null) {
            return self::empty();
        }

        $sequences = [];

        foreach ($stored as $field => $keys) {
            if (!\is_string($field) || !\is_array($keys)) {
                continue;
            }

            $ordered = [];

            foreach ($keys as $key) {
                if (\is_string($key)) {
                    $ordered[] = $key;
                }
            }

            $sequences[$field] = $ordered;
        }

        return new self($sequences);
    }
}
