<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Report;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportResult;
use Shondalai\VidyaLMS\Core\Registry\Report\RowPerPerson;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;

/** Bounded, permission-scoped evidence exports through the CSV/JSON report writers. */
final class TrainingEvidenceReport implements TabularReportInterface, FiltersByVisibility, RowPerPerson
{
    public function __construct(
        private readonly string $report,
        private readonly DatabasePort $database,
        private readonly UsersPort $users,
        private readonly TranslatorPort $translator,
    ) {
        if (!in_array($report, ['assignment-history', 'active-learning-time', 'training-activity'], true)) {
            throw new \InvalidArgumentException('Unknown evidence report.');
        }
    }

    public function key(): string
    {
        return $this->report;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.report.' . $this->report);
    }

    public function requiredCapability(): string
    {
        return Capability::VIEW_REPORTS;
    }

    public function parameterSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'course_id' => ['type' => 'integer', 'minimum' => 1],
            'user_id' => ['type' => 'integer', 'minimum' => 1],
        ]];
    }

    /** @return list<string> */
    private function fields(): array
    {
        return array_merge(['id', 'user_id', 'learner', 'course_id', 'lesson_id'], match ($this->report) {
            'assignment-history' => [
                'lesson', 'attempt_number', 'status', 'submitted_at', 'graded_at', 'grader_id',
                'points_awarded', 'feedback', 'assessment', 'files',
            ],
            'active-learning-time' => [
                'module_id', 'module', 'lesson', 'status', 'seconds_spent',
                'first_accessed_at', 'last_heartbeat_at', 'completed_at',
            ],
            default => ['action', 'occurred_at', 'context'],
        });
    }

    public function columns(ReportContext $context): array
    {
        return array_map(fn (string $field): ColumnDefinition => ColumnDefinition::of(
            $field,
            $this->translator->translate('vidyalms.evidence.' . $field),
            $field === 'seconds_spent' ? ColumnDefinition::TYPE_INTEGER : ColumnDefinition::TYPE_STRING,
        ), $this->fields());
    }

    public function generate(ReportContext $context): array
    {
        return $this->describe($context)->facts;
    }

    public function describe(ReportContext $context): ReportResult
    {
        $query = $this->base($context);
        $total = $this->database->count(clone $query);
        $facts = ['records' => $total];
        if ($this->report === 'active-learning-time') {
            $row = $this->database->selectOne($query->select([])->aggregate(Aggregate::sum('r.seconds_spent', 'total_seconds')));
            $facts['total_seconds'] = (int) ($row['total_seconds'] ?? 0);
        }
        return ReportResult::of($facts, [], $total);
    }

    public function rows(ReportContext $context): iterable
    {
        $after = 0;
        do {
            $page = $this->database->selectAll($this->base($context)->where('r.id', '>', $after)->orderBy('r.id')->limit(250));
            $names = $this->users->namesFor(array_map(static fn (array $row): int => (int) $row['user_id'], $page));
            $files = [];
            if ($this->report === 'assignment-history' && $page !== []) {
                $fileQuery = Query::from('submission_files')->whereIn('submission_id', array_column($page, 'id'))->orderBy('id');
                foreach ($this->database->selectAll($fileQuery) as $file) {
                    $files[(int) $file['submission_id']][] = [
                        'filename' => $file['filename'], 'mime' => $file['mime'], 'size_bytes' => $file['size_bytes'],
                    ];
                }
            }
            foreach ($page as $row) {
                $after = (int) $row['id'];
                $row['learner'] = $names[(int) $row['user_id']] ?? '';
                if ($this->report === 'assignment-history') {
                    $row['files'] = json_encode($files[$after] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                }
                yield array_intersect_key($row, array_flip($this->fields()));
            }
        } while (count($page) === 250);
    }

    private function base(ReportContext $context): Query
    {
        if ($this->report === 'assignment-history') {
            $query = Query::from('submissions', 'r')->join('assignments', 'a', 'r.assignment_id', 'a.id')
                ->join('lessons', 'l', 'a.lesson_id', 'l.id')->select([
                    'r.id', 'r.user_id', 'l.course_id', 'l.id AS lesson_id', 'l.title AS lesson',
                    'r.attempt_number', 'r.status', 'r.submitted_at', 'r.graded_at', 'r.grader_id',
                    'r.points_awarded', 'r.feedback', 'r.assessment_json AS assessment',
                ]);
            $courseColumn = 'l.course_id';
            $dateColumn = 'r.submitted_at';
        } elseif ($this->report === 'active-learning-time') {
            $query = Query::from('lesson_progress', 'r')->join('lessons', 'l', 'r.lesson_id', 'l.id')
                ->leftJoin('modules', 'm', 'l.module_id', 'm.id')->select([
                    'r.id', 'r.user_id', 'l.course_id', 'r.lesson_id', 'l.module_id', 'm.title AS module',
                    'l.title AS lesson', 'r.status', 'r.seconds_spent', 'r.first_accessed_at', 'r.last_heartbeat_at', 'r.completed_at',
                ]);
            $courseColumn = 'l.course_id';
            $dateColumn = 'r.last_heartbeat_at';
        } else {
            $query = Query::from('activity_log', 'r')->select([
                'r.id', 'r.user_id', 'r.course_id', 'r.lesson_id', 'r.action', 'r.occurred_at', 'r.context',
            ]);
            $courseColumn = 'r.course_id';
            $dateColumn = 'r.occurred_at';
        }
        $courseId = $context->courseId ?? $context->int('course_id');
        $scope = $context->visibility;
        if ($scope !== null && !$scope->isSiteWide()) {
            if ($scope->type === VisibilityScope::TYPE_COURSE) {
                $courseId = $scope->id;
            } elseif (
                $scope->type !== VisibilityScope::TYPE_USER || $courseId === null
                || $this->database->count(Query::from('course_instructors')
                    ->where('course_id', '=', $courseId)->where('user_id', '=', $scope->id)) === 0
            ) {
                throw ValidationException::forField('course_id', 'Select a course you teach to review its training evidence.');
            }
        }
        if ($courseId !== null) {
            $query->where($courseColumn, '=', $courseId);
        }
        if ($context->int('user_id') !== null) {
            $query->where('r.user_id', '=', $context->int('user_id'));
        }
        // Time totals are cumulative; filtering their last heartbeat would misrepresent period activity.
        if ($this->report !== 'active-learning-time') {
            if ($context->from !== null) {
                $query->where($dateColumn, '>=', $context->from->format('Y-m-d H:i:s'));
            }
            if ($context->until !== null) {
                $query->where($dateColumn, '<', $context->until->format('Y-m-d H:i:s'));
            }
        }
        return $query;
    }
}
