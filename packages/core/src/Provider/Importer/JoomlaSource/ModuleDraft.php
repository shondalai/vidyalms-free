<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

/**
 * One level of foreign curriculum between a course and its lessons, whatever
 * the product calls it: an OSCampus module, a Shika module, a Guru day.
 *
 * Guru is why this exists rather than the reader flattening everything onto
 * the course. Its physical table is `guru_days` and its own screens call a
 * day a module, so a day is a module here and the vocabulary is settled by
 * what the product means rather than by what its column names say.
 */
final class ModuleDraft
{
    /**
     * @param list<LessonDraft> $lessons
     */
    private function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly ?string $summary,
        public readonly array $lessons,
    ) {
    }

    /**
     * @param list<LessonDraft> $lessons
     */
    public static function of(string $key, string $title, ?string $summary, array $lessons): self
    {
        return new self($key, $title, $summary, $lessons);
    }
}
