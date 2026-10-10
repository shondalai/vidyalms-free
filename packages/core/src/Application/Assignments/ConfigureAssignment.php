<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assignments;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Assignment;
use Shondalai\VidyaLMS\Core\Domain\Assignments\AssignmentRubric;
use Shondalai\VidyaLMS\Core\Domain\Assignments\AssignmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Creates or updates the assignment configuration of a lesson. One assignment
 * per lesson: a second configuration call updates the existing row.
 */
final class ConfigureAssignment
{
    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly AssignmentRepository $assignments,
        private readonly CourseAccess $access,
        private readonly DatabasePort $database,
    ) {
    }

    /** @param array<mixed> $rubric */
    public function execute(
        Principal $principal,
        int $lessonId,
        ?string $instructions = null,
        float $maxPoints = 100.0,
        ?string $allowedFileTypes = null,
        ?int $maxFileSizeKb = null,
        int $maxFiles = 1,
        ?string $dueAt = null,
        bool $allowResubmission = false,
        ?float $passPercentage = null,
        array $rubric = [],
    ): Assignment {
        $this->access->requireSignedIn($principal);

        $lesson = $this->database->selectOne(
            Query::from('lessons')->select(['id', 'type', 'course_id'])->where('id', '=', $lessonId),
        );

        if ($lesson === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Lesson', $lessonId);
        }

        $this->access->require($principal, (int) $lesson['course_id'], Capability::TEACH);

        if ((string) $lesson['type'] !== 'assignment') {
            throw ValidationException::forField('lesson_id', 'The lesson is not an assignment lesson.');
        }

        if (!is_finite($maxPoints) || $maxPoints <= 0) {
            throw ValidationException::forField('max_points', 'Max points must be greater than zero.');
        }

        if ($maxFiles < 1) {
            throw ValidationException::forField('max_files', 'At least one file must be allowed.');
        }

        if ($passPercentage !== null && (!is_finite($passPercentage) || $passPercentage < 0 || $passPercentage > 100)) {
            throw ValidationException::forField('pass_percentage', 'Pass percentage must be between 0 and 100.');
        }
        $rubric = AssignmentRubric::configure($rubric, $maxPoints);

        if ($maxFileSizeKb !== null && $maxFileSizeKb < 1) {
            throw ValidationException::forField('max_file_size_kb', 'Max file size must be a positive number of kilobytes.');
        }

        $assignment = new Assignment(
            null,
            $lessonId,
            $instructions,
            $maxPoints,
            $this->parseAllowedFileTypes($allowedFileTypes),
            $maxFileSizeKb,
            $maxFiles,
            $this->parseDueAt($dueAt),
            $allowResubmission,
            $passPercentage,
            $rubric,
        );

        return $this->assignments->save($assignment);
    }

    /**
     * @return list<string>
     */
    private function parseAllowedFileTypes(?string $allowedFileTypes): array
    {
        if ($allowedFileTypes === null || trim($allowedFileTypes) === '') {
            return [];
        }

        $extensions = [];

        foreach (explode(',', $allowedFileTypes) as $part) {
            $extension = strtolower(ltrim(trim($part), '.'));

            if (preg_match('/^[a-z0-9]{1,20}$/', $extension) !== 1) {
                throw ValidationException::forField(
                    'allowed_file_types',
                    'Allowed file types must be a comma separated list of extensions.',
                );
            }

            $extensions[] = $extension;
        }

        return array_values(array_unique($extensions));
    }

    private function parseDueAt(?string $dueAt): ?\DateTimeImmutable
    {
        if ($dueAt === null || trim($dueAt) === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat(self::DATETIME, $dueAt, new \DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format(self::DATETIME) !== $dueAt) {
            throw ValidationException::forField('due_at', 'The due date must be a UTC datetime formatted Y-m-d H:i:s.');
        }

        return $parsed;
    }
}
