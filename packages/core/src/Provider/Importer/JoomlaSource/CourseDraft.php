<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseVisibility;

/**
 * One foreign course, read and normalised, before anything has been written.
 *
 * It is the whole boundary between the three Joomla source importers and the
 * writer they share. Each importer knows one product's schema and nothing
 * about use cases; CurriculumWriter knows the use cases and nothing about
 * Shika, Guru or OSCampus. That split is what keeps three importers written
 * from documentation from inventing three different answers to "what happens
 * on a re-run", which is the mistake the row-level error model exists to stop
 * being made three more times.
 *
 * The keys on every draft are the product's own natural keys ("course:8",
 * "lesson:41"), not positions in a result set: a re-run has to find what the
 * last one made, and a position changes the moment a row is deleted. The
 * importer prefixes them with its own scope before they reach the import map.
 */
final class CourseDraft
{
    /**
     * @param list<ModuleDraft>  $modules
     * @param list<LearnerDraft> $learners
     */
    private function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly ?string $summary,
        public readonly ?string $description,
        public readonly ?string $level,
        public readonly CourseVisibility $visibility,
        public readonly bool $publishedInSource,
        public readonly array $modules,
        public readonly array $learners,
    ) {
    }

    /**
     * @param list<ModuleDraft>  $modules
     * @param list<LearnerDraft> $learners
     */
    public static function of(
        string $key,
        string $title,
        ?string $summary,
        ?string $description,
        ?string $level,
        CourseVisibility $visibility,
        bool $publishedInSource,
        array $modules,
        array $learners,
    ): self {
        return new self(
            $key,
            $title,
            $summary,
            $description,
            $level,
            $visibility,
            $publishedInSource,
            $modules,
            $learners,
        );
    }
}
