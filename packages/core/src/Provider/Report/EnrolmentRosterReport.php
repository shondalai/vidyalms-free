<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Report;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
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

/**
 * Who is enrolled on a course, and where each of them has got to.
 *
 * It is the second free report and the first tabular one, and it exists so the
 * free `csv` and `json` writers have something to write: `course-overview`
 * returns six scalars and can never be a CSV, so a seam whose only free report
 * was scalar would have shipped two providers nothing could reach.
 *
 * It pages by keyset on the enrolment id rather than by offset, because an
 * export that runs while people enrol would otherwise duplicate and skip rows,
 * and a roster with a learner missing is worse than one that took a second
 * longer.
 *
 * It declares {@see FiltersByVisibility} and honours the course scope it is
 * given. It does **not** declare `HonoursOrgScope`, so an organisation-scoped
 * run refuses it rather than trusting it to ignore the organisation politely:
 * it was written before organisations existed and a provider must not inherit a
 * promise about a scope kind that did not exist when it was written.
 *
 * It declares {@see RowPerPerson} because every row of it is one learner, and
 * the delivery refusal that reads that marker returns early without it: a
 * report that emits `user_id` and does not declare it is a report the
 * cross-organisation attachment rule silently allows. A structural test now
 * asserts the pair, so the marker cannot be omitted by forgetting it.
 */
final class EnrolmentRosterReport implements TabularReportInterface, FiltersByVisibility, RowPerPerson
{
    public const KEY = 'enrolment-roster';

    /** One page of the keyset walk. Bounded so one report cannot hold a course in memory. */
    private const PAGE = 500;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly UsersPort $users,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.report.enrolment_roster');
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
                'status' => [
                    'type' => 'string',
                    'enum' => ['active', 'suspended', 'completed', 'cancelled'],
                    'description' => 'Limit the roster to one enrolment status.',
                ],
            ],
        ];
    }

    public function columns(ReportContext $context): array
    {
        return [
            $this->column('enrolment_id', ColumnDefinition::TYPE_INTEGER),
            $this->column('user_id', ColumnDefinition::TYPE_INTEGER),
            $this->column('learner'),
            $this->column('status'),
            $this->column('enrolled_at', ColumnDefinition::TYPE_DATETIME),
            $this->column('completed_at', ColumnDefinition::TYPE_DATETIME),
            $this->column('progress_percent', ColumnDefinition::TYPE_DECIMAL, 'progress'),
        ];
    }

    /** The label key is derived from the column key, so the two cannot drift. */
    private function column(string $key, string $type = ColumnDefinition::TYPE_STRING, ?string $labelKey = null): ColumnDefinition
    {
        return ColumnDefinition::of(
            $key,
            $this->translator->translate('vidyalms.roster.column.' . ($labelKey ?? $key)),
            $type,
        );
    }

    public function generate(ReportContext $context): array
    {
        return $this->describe($context)->facts;
    }

    public function describe(ReportContext $context): ReportResult
    {
        $courseId = $this->courseFor($context);
        $total = $this->database->count($this->base($courseId, $context));

        return ReportResult::of([
            'course_id' => $courseId,
            'enrolled_count' => $total,
        ], [], $total);
    }

    public function rows(ReportContext $context): iterable
    {
        $courseId = $this->courseFor($context);
        $after = 0;

        while (true) {
            $page = $this->database->selectAll(
                $this->base($courseId, $context)
                    ->select(['id', 'user_id', 'status', 'enrolled_at', 'completed_at', 'progress_percent'])
                    ->where('id', '>', $after)
                    ->orderBy('id')
                    ->limit(self::PAGE),
            );

            if ($page === []) {
                return;
            }

            $ids = array_values(array_map(static fn (array $row): int => (int) $row['user_id'], $page));
            $names = $this->users->namesFor($ids);

            foreach ($page as $row) {
                $after = (int) $row['id'];
                $userId = (int) $row['user_id'];

                yield [
                    'enrolment_id' => $after,
                    'user_id' => $userId,
                    // A host account that is gone is absent from the map
                    // rather than mapped to an empty name, so the roster says
                    // the account went rather than that the person is nameless.
                    'learner' => $names[$userId] ?? '',
                    'status' => (string) ($row['status'] ?? ''),
                    'enrolled_at' => $row['enrolled_at'] ?? null,
                    'completed_at' => $row['completed_at'] ?? null,
                    'progress_percent' => $row['progress_percent'] ?? 0,
                ];
            }

            if (\count($page) < self::PAGE) {
                return;
            }
        }
    }

    private function base(int $courseId, ReportContext $context): Query
    {
        $query = Query::from('enrolments')->where('course_id', '=', $courseId);
        $status = $context->string('status');

        if ($status !== null) {
            $query->where('status', '=', $status);
        }

        return $query;
    }

    /**
     * The course this run is about, taken from the scope when there is one.
     *
     * The scope wins over the parameter. A filter chosen at run time must never
     * widen a run past the condition the definition was given, and reading the
     * parameter first is exactly how a course-scoped caller reaches a course
     * they were not scoped to.
     *
     * **A scope that is present, not site-wide and not a course is not a
     * scope this report may fall through.** It used to: the site read and
     * export routes hand a user-typed scope for anybody who is not a manager,
     * the type did not match TYPE_COURSE, and the caller's own `course_id`
     * parameter was then used unchecked. A principal holding VIEW_REPORTS and
     * teaching nothing could read and export any course's roster, including
     * the learner names and ids, while the course-scoped route answered them
     * 403 for the same course. The shipped WordPress instructor role holds
     * exactly that capability.
     *
     * A user-typed scope is resolved against `course_instructors` rather than
     * refused outright, so an instructor keeps the export of the courses they
     * actually teach. Every other scope kind is refused, which is what
     * SiteLearningReport::base() already does for the same scope kinds.
     */
    private function courseFor(ReportContext $context): int
    {
        $scope = $context->visibility;

        if ($scope !== null && !$scope->isSiteWide()) {
            if ($scope->type === VisibilityScope::TYPE_COURSE) {
                return $scope->id;
            }

            if ($scope->type !== VisibilityScope::TYPE_USER) {
                throw ValidationException::forField('scope', 'This report cannot be narrowed to that kind of scope.');
            }

            $courseId = $this->requestedCourse($context);

            if (!$this->teaches($scope->id, $courseId)) {
                // The same answer whether the course exists or not, so the
                // refusal cannot be read as a course directory.
                throw ValidationException::forField(
                    'course_id',
                    'This roster is limited to the courses you teach.',
                );
            }

            return $courseId;
        }

        return $this->requestedCourse($context);
    }

    private function requestedCourse(ReportContext $context): int
    {
        $courseId = $context->courseId ?? $context->int('course_id');

        if ($courseId === null || $courseId < 1) {
            throw ValidationException::forField('course_id', 'A positive course id is required.');
        }

        return $courseId;
    }

    /** Whether that person is recorded as an instructor of that course. */
    private function teaches(int $userId, int $courseId): bool
    {
        return $this->database->count(
            Query::from('course_instructors')
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $courseId),
        ) > 0;
    }
}
