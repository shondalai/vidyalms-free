<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Report;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportResult;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;

/**
 * SQL-aggregated, paginated course outcomes with an explicit all-time
 * denominator.
 *
 * **It is a registered report now, and it was a bespoke handler.**
 * `GET /manage/reports/overview` went through its own use case, reimplemented
 * its own scope rule, paged by offset, and sat on the exact path space the
 * designed `GET /manage/reports/{key}` needs, with route matching being
 * first-registration-wins and no literal-over-parameter precedence. Two report
 * shapes and a route-space collision, in one product.
 *
 * The route survives as a **stable alias**, because the operations screen the
 * UX migration built calls it through the generated SDK and a re-homing that
 * broke it would be a refactor with a customer-visible failure attached. So
 * `/manage/reports/overview` answers the same shape as before, is registered
 * first, and the parameterised route is registered strictly after it.
 *
 * **The scope is given, never derived.** The old code asked `AccessGuard` for
 * MANAGE or ADMIN inside the report and joined `course_instructors` when the
 * answer was no. That is the group-leader leak in embryo: a provider deciding
 * its own reach. It is handed a {@see VisibilityScope} now, and the caller that
 * authorised the run is what built it.
 */
final class SiteLearningReport implements TabularReportInterface, FiltersByVisibility
{
    public const KEY = 'site-learning';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.report.site_learning');
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
            'properties' => [
                'page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000000, 'default' => 1],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                'search' => ['type' => 'string', 'maxLength' => 200],
                'state' => ['type' => 'string', 'enum' => ['draft', 'published', 'archived']],
            ],
        ];
    }

    public function columns(ReportContext $context): array
    {
        return [
            ColumnDefinition::of('course_id', $this->columnLabel('course_id'), ColumnDefinition::TYPE_INTEGER),
            ColumnDefinition::of('title', $this->columnLabel('title')),
            ColumnDefinition::of('state', $this->columnLabel('state')),
            // The three counts, which is what small-cell suppression acts on:
            // a course with two enrolments names two people.
            ColumnDefinition::count('enrolments', $this->columnLabel('enrolments')),
            ColumnDefinition::count('active', $this->columnLabel('active')),
            ColumnDefinition::count('completed', $this->columnLabel('completed')),
            // A ratio taken on the two counts above it. Suppression acts on
            // counts alone, so a completion rate of 0.25 published beside an
            // enrolment count of 12 named the three completions the policy had
            // just blanked, exactly, by one multiplication.
            ColumnDefinition::derived(
                'completion_rate',
                $this->columnLabel('completion_rate'),
                ['completed', 'enrolments'],
            ),
        ];
    }

    /** Not label(), which is the provider's own name on the picker. */
    private function columnLabel(string $key): string
    {
        return $this->translator->translate('vidyalms.site_learning.column.' . $key);
    }

    public function generate(ReportContext $context): array
    {
        return $this->describe($context)->facts;
    }

    /**
     * The shape `/manage/reports/overview` has always answered, unchanged.
     *
     * The pagination stays offset-based here and only here, because the alias
     * has published `page` and `total` on the contract since before this seam
     * existed and a keyset cursor is not the same operation. `rows()`, which is
     * what the export walks, does not page at all.
     */
    public function describe(ReportContext $context): ReportResult
    {
        [$page, $perPage, $search, $state] = $this->filters($context);
        $courses = $this->base($context, $search, $state);
        $total = $this->database->count(clone $courses);
        $base = (clone $courses)->leftJoin('enrolments', 'e', 'c.id', 'e.course_id');
        $aggregates = self::aggregates();
        $summary = $this->database->selectOne((clone $base)->select([])->aggregate(...$aggregates)) ?? [];
        $rows = $this->database->selectAll(
            $base->select(['c.id', 'c.title', 'c.state'])->aggregate(...$aggregates)
                ->groupBy('c.id')->groupBy('c.title')->groupBy('c.state')
                ->orderBy('c.title')->orderBy('c.id')
                ->limit($perPage)->offset(($page - 1) * $perPage),
        );

        return ReportResult::of([
            'items' => array_map(static fn (array $row): array => [
                'course_id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'state' => (string) $row['state'],
            ] + self::metrics($row), $rows),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'summary' => ['courses' => $total] + self::metrics($summary),
            'generated_at' => $this->clock->now()->format(DATE_ATOM),
            'period' => 'all_time',
            'scope' => $context->visibility === null || $context->visibility->isSiteWide() ? 'sitewide' : 'assigned_courses',
            'completion_basis' =>
                'Enrolments with completed_at divided by all enrolment records in the filtered courses.',
        ], [], $total);
    }

    public function rows(ReportContext $context): iterable
    {
        [, , $search, $state] = $this->filters($context);
        $aggregates = self::aggregates();
        $rows = $this->database->selectAll(
            $this->base($context, $search, $state)
                ->leftJoin('enrolments', 'e', 'c.id', 'e.course_id')
                ->select(['c.id', 'c.title', 'c.state'])->aggregate(...$aggregates)
                ->groupBy('c.id')->groupBy('c.title')->groupBy('c.state')
                ->orderBy('c.id'),
        );

        foreach ($rows as $row) {
            yield [
                'course_id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'state' => (string) $row['state'],
            ] + self::metrics($row);
        }
    }

    /**
     * The narrowing this run may see.
     *
     * A site-wide scope, or none at all, reads every course. A user-typed scope
     * is the instructor case and joins `course_instructors`, which is the join
     * the old code wrote after asking the guard itself. A course-typed scope,
     * which is what a group leader's run will carry, is an explicit id list.
     */
    private function base(ReportContext $context, string $search, ?string $state): Query
    {
        $courses = Query::from('courses', 'c');
        $scope = $context->visibility;

        if ($scope !== null && !$scope->isSiteWide()) {
            if ($scope->type === VisibilityScope::TYPE_USER) {
                $courses->join('course_instructors', 'ci', 'c.id', 'ci.course_id')
                    ->where('ci.user_id', '=', $scope->id);
            } elseif ($scope->type === VisibilityScope::TYPE_COURSE) {
                $courses->whereIn('c.id', $scope->ids());
            } else {
                // A scope type this report cannot honour is refused rather
                // than widened. The published rule for a provider handed a
                // scope it does not understand is that it says so.
                throw ValidationException::forField('scope', 'This report cannot be narrowed to that kind of scope.');
            }
        }

        if ($search !== '') {
            $courses->whereContains('c.title', $search);
        }

        if ($state !== null) {
            $courses->where('c.state', '=', $state);
        }

        return $courses;
    }

    /**
     * @return array{0: int, 1: int, 2: string, 3: ?string}
     */
    private function filters(ReportContext $context): array
    {
        $page = $context->int('page') ?? 1;
        $perPage = $context->int('per_page') ?? 25;
        $search = $context->string('search') ?? '';
        $state = $context->string('state');

        if ($page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100 || mb_strlen($search) > 200) {
            throw ValidationException::forField('page', 'Use 1 to 100 rows per page and a search of at most 200 characters.');
        }

        if ($state !== null && !\in_array($state, ['draft', 'published', 'archived'], true)) {
            throw ValidationException::forField('state', 'Choose a supported course state.');
        }

        return [$page, $perPage, $search, $state];
    }

    /** @return list<Aggregate> */
    private static function aggregates(): array
    {
        return [
            Aggregate::countOf('e.id', 'enrolments'),
            Aggregate::countWhere([Predicate::compare('e.status', '=', 'active')], 'active'),
            Aggregate::countWhere([Predicate::isNotNull('e.completed_at')], 'completed'),
        ];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, int|float|null>
     */
    private static function metrics(array $row): array
    {
        $enrolments = (int) ($row['enrolments'] ?? 0);
        $completed = (int) ($row['completed'] ?? 0);

        return [
            'enrolments' => $enrolments,
            'active' => (int) ($row['active'] ?? 0),
            'completed' => $completed,
            'completion_rate' => $enrolments === 0 ? null : $completed / $enrolments,
        ];
    }
}
