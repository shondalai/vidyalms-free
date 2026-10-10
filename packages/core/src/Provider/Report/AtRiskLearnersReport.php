<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Report;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
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
 * Who on this course looks like they are falling behind, and why.
 *
 * **It is advisory by design and the design is a legal one.** GDPR Article 22
 * gives a data subject the right not to be subject to a decision based solely on
 * automated processing producing legal or similarly significant effects. A score
 * that prompts a human tutor does not engage that; a score that automatically
 * fails somebody or revokes an entitlement does. So this report must never by
 * itself gate content, revoke an entitlement or fail anyone, and the three
 * things that keep it advisory are columns rather than a promise in a docblock:
 *
 * - every flagged row carries a **visible reason**, in words, so the human
 *   reading it can disagree with it;
 * - every row carries a **route to a human**, which is the learner id an
 *   instructor contacts rather than a button that acts;
 * - every row carries **`acknowledged`**, which is where a record of who acted
 *   on it goes, and which is false here because nothing in the free build acts.
 *
 * This costs nothing now and is expensive to retrofit after the first
 * enterprise data protection impact assessment.
 *
 * **The thresholds are parameters and not constants**, because "behind" means
 * something different on a four-week induction and a two-year apprenticeship,
 * and a hard-coded fortnight is a report that is wrong for half its sites.
 *
 * It applies no suppression and no disclosure of its own. Both are one stage of
 * the export pipeline, applied identically on screen and in the file, and a
 * report that suppressed its own cells would be suppressing them twice on one
 * path and once on the other.
 */
final class AtRiskLearnersReport implements TabularReportInterface, FiltersByVisibility, RowPerPerson
{
    public const KEY = 'at-risk-learners';

    /** One page of the keyset walk. */
    private const PAGE = 500;

    /** Days of silence that count as silence, when the caller names none. */
    public const DEFAULT_IDLE_DAYS = 14;

    /** The progress percentage below which a learner is flagged, when the caller names none. */
    public const DEFAULT_PROGRESS_FLOOR = 20;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly UsersPort $users,
        private readonly TranslatorPort $translator,
        private readonly ClockPort $clock,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.report.at_risk_learners');
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
                'idle_days' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 365,
                    'default' => self::DEFAULT_IDLE_DAYS,
                    'description' => 'How many days of no activity count as falling behind.',
                ],
                'progress_floor' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'default' => self::DEFAULT_PROGRESS_FLOOR,
                    'description' => 'The progress percentage below which a learner is flagged.',
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
            $this->column('progress_percent', ColumnDefinition::TYPE_DECIMAL),
            $this->column('last_seen_at', ColumnDefinition::TYPE_DATETIME),
            $this->column('idle_days', ColumnDefinition::TYPE_INTEGER),
            // The three columns that keep this advisory rather than automatic.
            $this->column('reason'),
            $this->column('contact_user_id', ColumnDefinition::TYPE_INTEGER),
            $this->column('acknowledged', ColumnDefinition::TYPE_BOOLEAN),
        ];
    }

    public function generate(ReportContext $context): array
    {
        return $this->describe($context)->facts;
    }

    public function describe(ReportContext $context): ReportResult
    {
        $courseId = $this->courseFor($context);
        $flagged = 0;

        foreach ($this->rows($context) as $ignored) {
            $flagged++;
        }

        return ReportResult::of(
            [
                'course_id' => $courseId,
                'flagged_count' => $flagged,
                'idle_days' => $this->idleDays($context),
                'progress_floor' => $this->progressFloor($context),
            ],
            [
                // The note travels with the numbers, so a screen that renders
                // the facts and not the rows still carries the warning.
                $this->translator->translate('vidyalms.report.at_risk_learners.advisory'),
            ],
            $flagged,
        );
    }

    public function rows(ReportContext $context): iterable
    {
        $courseId = $this->courseFor($context);
        $idleDays = $this->idleDays($context);
        $floor = $this->progressFloor($context);
        $cutoff = $this->clock->now()->modify('-' . $idleDays . ' days');
        $after = 0;

        while (true) {
            $page = $this->database->selectAll(
                Query::from('enrolments', 'e')
                    ->select(['e.id', 'e.user_id', 'e.enrolled_at'])
                    ->where('e.course_id', '=', $courseId)
                    ->where('e.status', '=', 'active')
                    ->where('e.id', '>', $after)
                    ->orderBy('e.id')
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
                $progress = $this->progressOf($after);
                $lastSeen = $this->lastSeenOf($userId, $courseId);
                $reasons = [];

                if ($lastSeen === null || $lastSeen < $cutoff) {
                    $reasons[] = $this->translator->translate('vidyalms.report.at_risk_learners.reason.idle');
                }

                if ($progress < (float) $floor) {
                    $reasons[] = $this->translator->translate('vidyalms.report.at_risk_learners.reason.progress');
                }

                if ($reasons === []) {
                    continue;
                }

                yield [
                    'enrolment_id' => $after,
                    'user_id' => $userId,
                    'learner' => $names[$userId] ?? '',
                    'progress_percent' => $progress,
                    'last_seen_at' => $lastSeen?->format('Y-m-d H:i:s'),
                    'idle_days' => $lastSeen === null
                        ? null
                        : (int) $lastSeen->diff($this->clock->now())->days,
                    // Words, not a score. A number nobody can argue with is
                    // exactly what Article 22 is about.
                    'reason' => implode('; ', $reasons),
                    // The route to a human, which is a person to contact and
                    // never a control that acts.
                    'contact_user_id' => $userId,
                    // Where a record of who acted on it goes. False here
                    // because nothing in the free build acts on this report,
                    // and a column that is always false is still the column a
                    // later action writes into.
                    'acknowledged' => false,
                ];
            }

            if (\count($page) < self::PAGE) {
                return;
            }
        }
    }

    private function progressOf(int $enrolmentId): float
    {
        $row = $this->database->selectOne(
            Query::from('course_progress')
                ->select(['percent'])
                ->where('enrolment_id', '=', $enrolmentId),
        );

        return $row === null ? 0.0 : (float) ($row['percent'] ?? 0);
    }

    private function lastSeenOf(int $userId, int $courseId): ?\DateTimeImmutable
    {
        $row = $this->database->selectOne(
            Query::from('activity_log')
                ->select(['occurred_at'])
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $courseId)
                ->orderBy('occurred_at', Query::SORT_DESC)
                ->orderBy('id', Query::SORT_DESC)
                ->limit(1),
        );

        if ($row === null || !\is_string($row['occurred_at'] ?? null)) {
            return null;
        }

        return new \DateTimeImmutable((string) $row['occurred_at'], new \DateTimeZone('UTC'));
    }

    private function idleDays(ReportContext $context): int
    {
        $value = $context->int('idle_days');

        return $value !== null && $value >= 1 && $value <= 365 ? $value : self::DEFAULT_IDLE_DAYS;
    }

    private function progressFloor(ReportContext $context): int
    {
        $value = $context->int('progress_floor');

        return $value !== null && $value >= 0 && $value <= 100 ? $value : self::DEFAULT_PROGRESS_FLOOR;
    }

    /**
     * The course this run is about, taken from the scope when there is one.
     *
     * The scope wins over the parameter, on the rule the roster report already
     * states: a filter chosen at run time must never widen a run past the
     * condition the definition was given, and reading the parameter first is
     * exactly how a course-scoped caller reaches a course they were not scoped
     * to. Any scope kind this report cannot honour is refused rather than
     * politely ignored.
     */
    private function courseFor(ReportContext $context): int
    {
        $scope = $context->visibility;

        if ($scope !== null && !$scope->isSiteWide()) {
            if ($scope->type === VisibilityScope::TYPE_COURSE) {
                return $scope->id;
            }

            throw ValidationException::forField('scope', 'This report cannot be narrowed to that kind of scope.');
        }

        $courseId = $context->int('course_id') ?? 0;

        if ($courseId < 1) {
            throw ValidationException::forField('course_id', 'This report is about one course.');
        }

        return $courseId;
    }

    private function column(string $key, string $type = ColumnDefinition::TYPE_STRING): ColumnDefinition
    {
        return ColumnDefinition::of(
            $key,
            $this->translator->translate('vidyalms.at_risk.column.' . $key),
            $type,
        );
    }
}
