<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Curriculum;

/**
 * What an archive actually did, so the response can say it.
 *
 * Archiving a lesson is not a local edit: it changes what the course
 * contains, which changes who has completed it. The screen that archived it
 * has to be able to tell the author that a recompute is running and roughly
 * how many people it touches, because a certificate arriving twenty seconds
 * later with nobody having done anything is otherwise inexplicable.
 */
final class ArchiveOutcome
{
    public const RECOMPUTE_QUEUED = 'queued';
    public const RECOMPUTE_NOT_NEEDED = 'not_needed';

    private function __construct(
        public readonly int $lessonsArchived,
        public readonly int $enrolmentsAffected,
        public readonly string $progressRecompute,
    ) {
    }

    public static function queued(int $lessonsArchived, int $enrolmentsAffected): self
    {
        return new self($lessonsArchived, $enrolmentsAffected, self::RECOMPUTE_QUEUED);
    }

    /**
     * Nothing changed (the entity was already archived) or nobody is
     * enrolled, so no progress can have moved.
     */
    public static function notNeeded(int $lessonsArchived = 0, int $enrolmentsAffected = 0): self
    {
        return new self($lessonsArchived, $enrolmentsAffected, self::RECOMPUTE_NOT_NEEDED);
    }
}
