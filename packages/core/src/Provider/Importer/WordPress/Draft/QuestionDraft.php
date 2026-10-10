<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft;

/**
 * One question a WordPress LMS reader recognised, already translated into a
 * free question type and its definition.
 *
 * The reader owns the foreign vocabulary and the writer owns the use cases, so
 * this is the whole of what crosses between them. A type the free build has no
 * home for never becomes a draft at all: the reader records a row problem
 * naming the foreign type word and moves on, because a question silently
 * converted into a different type is worse than a question that is visibly
 * missing.
 */
final class QuestionDraft
{
    /**
     * @param string               $sourceId   the product's own question identity, stable across runs
     * @param string               $type       a registered free question type key
     * @param array<string, mixed> $definition the definition that type validates
     * @param float                $points     always greater than zero, because AddQuestion refuses zero
     * @param string|null          $note       one sentence saying what changed in translation, or null
     */
    private function __construct(
        public readonly string $sourceId,
        public readonly string $type,
        public readonly array $definition,
        public readonly float $points,
        public readonly ?string $note,
    ) {
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function of(
        string $sourceId,
        string $type,
        array $definition,
        float $points = 1.0,
        ?string $note = null,
    ): self {
        return new self($sourceId, $type, \Shondalai\VidyaLMS\Core\Provider\Importer\QuestionText::definition($definition), $points > 0 ? $points : 1.0, $note);
    }
}
