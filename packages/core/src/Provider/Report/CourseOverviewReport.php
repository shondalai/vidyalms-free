<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Report;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportDefinitionInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportResult;

/**
 * Headline numbers for one course, read-only over the Access and Progress
 * contexts' tables.
 *
 * It is scalar and therefore not exportable: six numbers can never be a CSV,
 * which is precisely why the tabular split exists and why `enrolment-roster`
 * ships beside it.
 *
 * **It no longer checks its own capability, and that is the point of the
 * change.** It called `AccessGuard::require()` inside `generate()`, which is
 * fine with one report and one capability and wrong the moment a group leader
 * exists: every provider then repeats both the capability check and the scope
 * filter, and the eleventh one forgets the second. Authorisation happens in
 * {@see \Shondalai\VidyaLMS\Core\Application\Assessment\GetCourseReport} and in
 * the reporting use cases beside it, and a provider is handed an
 * already-authorised context.
 *
 * Its private `courseIdFrom()` helper is gone with it. The context already
 * resolves and validates a course id, and the second report would otherwise
 * have copied the helper, which is how a validation rule ends up with eleven
 * implementations that disagree at the edges.
 */
final class CourseOverviewReport implements ReportDefinitionInterface, \Shondalai\VidyaLMS\Core\Registry\Report\CourseScopedReport
{
    public const KEY = 'course-overview';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.report.course_overview');
    }

    public function requiredCapability(): string
    {
        return Capability::VIEW_REPORTS;
    }

    public function parameterSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'additionalProperties' => true,
            'required' => ['course_id'],
            'properties' => [
                'course_id' => ['type' => 'integer', 'minimum' => 1],
            ],
        ];
    }

    public function generate(ReportContext $context): array
    {
        return $this->describe($context)->facts;
    }

    public function describe(ReportContext $context): ReportResult
    {
        $courseId = $context->courseId ?? $context->int('course_id');

        if ($courseId === null || $courseId < 1) {
            throw ValidationException::forField('course_id', 'A positive course id is required.');
        }

        $enrolments = $this->database->selectAll(
            Query::from('enrolments')
                ->select(['status', 'completed_at', 'progress_percent'])
                ->where('course_id', '=', $courseId),
        );

        $active = 0;
        $completed = 0;
        $progressSum = 0.0;

        foreach ($enrolments as $row) {
            if (($row['status'] ?? null) === 'active') {
                $active++;
            }

            if (($row['completed_at'] ?? null) !== null) {
                $completed++;
            }

            $progress = $row['progress_percent'] ?? 0;
            $progressSum += \is_numeric($progress) ? (float) $progress : 0.0;
        }

        $quizAverages = $this->database->selectAll(
            Query::from('course_progress', 'cp')
                ->select(['cp.quiz_average'])
                ->join('enrolments', 'e', 'cp.enrolment_id', 'e.id')
                ->where('e.course_id', '=', $courseId)
                ->whereNotNull('cp.quiz_average'),
        );

        $quizSum = 0.0;

        foreach ($quizAverages as $row) {
            $average = $row['quiz_average'] ?? 0;
            $quizSum += \is_numeric($average) ? (float) $average : 0.0;
        }

        $enrolled = \count($enrolments);
        $graded = \count($quizAverages);

        return ReportResult::of([
            'course_id' => $courseId,
            'enrolled_count' => $enrolled,
            'active_count' => $active,
            'completed_count' => $completed,
            'average_progress' => $enrolled > 0 ? round($progressSum / $enrolled, 2) : 0.0,
            'average_quiz_score' => $graded > 0 ? round($quizSum / $graded, 2) : null,
        ], [], $enrolled);
    }
}
