<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityGrant;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityLeaf;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityNode;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityRule;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityGrantRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;
use Shondalai\VidyaLMS\Core\Port\Scheduler\SchedulerPort;
use Shondalai\VidyaLMS\Core\Provider\Availability\CompletionCondition;
use Shondalai\VidyaLMS\Core\Provider\ScheduledTask\UnlockNoticeTask;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * The one entry point every reader of availability uses.
 *
 * Availability is evaluated on read, always. Nothing writes an unlocked row
 * when the moment arrives and there is no materialisation table, because a
 * design where a learner's access depends on a job having fired is broken on
 * both hosts: one lazy scheduler is triggered from frontend responses with a
 * floor of several minutes, and the other fires on page load or, with cron
 * disabled and no system cron, never.
 *
 * Three things happen in order and the order is the specification. The stored
 * trees are composed, ancestors first, so a closed module is the answer for
 * every lesson inside it and an author never copies a condition onto forty
 * children. Sequencing is ANDed in as an implicit leaf and never as a stored
 * condition, so flipping a course between linear and free rewrites no data.
 * Grants are applied last and are therefore always the last word.
 */
final class AvailabilityEngine implements AvailabilityScopeResolver
{
    public const SETTING_DEFAULT_VISIBILITY = 'availability.default_visibility';

    public const PROGRESSION_LINEAR = 'linear';
    public const PROGRESSION_FREE = 'free';

    public function __construct(
        private readonly SqlAvailabilityRuleRepository $rules,
        private readonly SqlAvailabilityGrantRepository $grants,
        private readonly AvailabilityComposer $composer,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly string $siteTimezone = 'UTC',
        private readonly ?SchedulerPort $scheduler = null,
        /**
         * Only for the one thing that is worth a line rather than a silent
         * skip: a grant whose scope no longer contains the learner.
         */
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?\Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryVisibility $categoryVisibility = null,
    ) {
    }

    /**
     * Whether a gated item's **title** may be published to a reader who is
     * not this learner: a search index, a sitemap, a catalogue outline.
     *
     * It is a different question from the per-learner gate and deliberately
     * not the same call. Indexing is not per learner, so asking the engine
     * for one learner's answer would either index everything or index
     * nothing; what an index may hold is decided by the item's own stored
     * rule and the site's default visibility. A hidden item is **absent**
     * rather than flagged, because the title itself is the leak, and a
     * platform that leaves restricted content in global search results is the
     * documented failure this answers.
     *
     * One core answer rather than two adapters' worth of judgement: the
     * search indexer and the REST outline are named alongside the use cases
     * in the design's enforcement list precisely because a guard written in
     * an adapter covers only the routes that adapter happens to own.
     *
     * An item with no stored rule is publishable, which is every item on a
     * site that gates nothing.
     */
    public function titleIsPublishable(GateSubject $subject): bool
    {
        $rule = $this->rules->find($subject, AvailabilitySurface::ContentGating);

        if ($rule === null || $rule->tree->isEmpty()) {
            return true;
        }

        // The rule's own per-item visibility, falling back to the site
        // setting, which is what the outline reads for the same decision.
        return !$rule->hidesByDefault();
    }

    /** @param array<string, mixed> $course */
    public function permitsEnrolment(Principal $principal, array $course): bool
    {
        $subject = new GateSubject(GateSubject::TYPE_COURSE, (int) $course['id']);
        $rule = $this->rules->find($subject, AvailabilitySurface::EnrolmentGating);
        if ($rule === null || $rule->tree->isEmpty()) {
            return true;
        }
        $context = AvailabilityContext::create(
            $principal,
            AvailabilitySurface::EnrolmentGating,
            $this->clock->now(),
            course: $course,
            subject: $subject,
            timezoneChain: [$this->siteTimezone],
        );
        $decisions = $this->composer->evaluateSet([
            $subject->key() => new SubjectTree($rule->tree, $subject),
        ], $context);
        return isset($decisions[$subject->key()]) && $decisions[$subject->key()]->isAvailable();
    }

    /**
     * The learner's active run, from the free denormalised pointer.
     *
     * It is a free column precisely so a free build can answer this without
     * joining a paid table, which is what makes the cohort scope check
     * possible with no cohorts installed.
     *
     * @param array<string, mixed>|null $enrolment
     */
    private function cohortOf(?array $enrolment): ?int
    {
        $cohortId = $enrolment['cohort_id'] ?? null;

        return \is_numeric($cohortId) && (int) $cohortId > 0 ? (int) $cohortId : null;
    }

    /**
     * Whether a grant's scope contains this learner in this render.
     *
     * A site scope always does. A course scope must name the course being
     * rendered. A cohort scope must name the learner's own active run. A
     * group scope names a Business table the free build does not carry, so it
     * is skipped rather than trusted: a scope nothing can evaluate must not
     * be allowed to win by being narrow.
     */
    private function scopeApplies(AvailabilityGrant $grant, int $courseId, ?int $cohortId): bool
    {
        return match ($grant->scopeType) {
            AvailabilityGrant::SCOPE_SITE => true,
            AvailabilityGrant::SCOPE_COURSE => $courseId === 0 || $grant->scopeId === $courseId,
            AvailabilityGrant::SCOPE_COHORT => $cohortId !== null && $grant->scopeId === $cohortId,
            default => false,
        };
    }

    /**
     * Schedules an unlock notice for every gated row of one course that names
     * an opening instant, and cancels the ones that no longer do.
     *
     * The instant comes from the composed outcome and never from a leaf.
     * Scheduling from the leaf is the obvious shortcut and it is wrong: under
     * an "and" of a date and a prerequisite the date leaf names 3 March while
     * the node names nothing, so a leaf-driven task would mail "your module
     * is now available" to a learner who still cannot enter.
     *
     * The dedupe key is what makes a rule edit safe: a task carrying one
     * replaces any pending task with the same key, so rescheduling a release
     * never double-fires.
     *
     * @param array<string, mixed> $enrolment
     * @param array<string, mixed>|null $course
     */
    public function refreshUnlockTasks(Principal $principal, int $courseId, array $enrolment, ?array $course = null): void
    {
        if ($this->scheduler === null) {
            return;
        }

        $enrolmentId = $enrolment['id'] ?? null;

        if (!\is_numeric($enrolmentId)) {
            return;
        }

        $lessonIds = [];

        foreach (
            $this->database->selectAll(
                Query::from('lessons')->select(['id'])->where('course_id', '=', $courseId),
            ) as $row
        ) {
            $lessonIds[] = (int) $row['id'];
        }

        if ($lessonIds === []) {
            return;
        }

        $hierarchy = $this->hierarchyFor($courseId, $lessonIds);
        $map = $this->forCourse($this->learnerOf($enrolment, $principal), $courseId, $hierarchy, $enrolment, $course);

        foreach ($hierarchy->subjects() as $subject) {
            $dedupeKey = self::unlockDedupeKey((int) $enrolmentId, $subject);
            $opensAt = $map->for($subject)->outcome->opensAt;

            if ($opensAt === null) {
                $this->scheduler->cancelByDedupeKey($dedupeKey);
                continue;
            }

            $this->scheduler->schedule(new ScheduledTask(
                UnlockNoticeTask::TYPE,
                [
                    'enrolment_id' => (int) $enrolmentId,
                    'course_id' => $courseId,
                    'subject_type' => $subject->type,
                    'subject_id' => $subject->id,
                ],
                $opensAt,
                $dedupeKey,
            ));
        }
    }

    public static function unlockDedupeKey(int $enrolmentId, GateSubject $subject): string
    {
        return sprintf('availability.unlock:%d:%s:%d', $enrolmentId, $subject->type, $subject->id);
    }

    /**
     * Every gated row of one course for one learner, in one pass.
     *
     * @param array<string, mixed>|null $enrolment
     * @param array<string, mixed>|null $course
     */
    public function forCourse(
        Principal $principal,
        int $courseId,
        GateHierarchy $hierarchy,
        ?array $enrolment = null,
        ?array $course = null,
        ?\DateTimeImmutable $asOf = null,
    ): AvailabilityMap {
        $subjects = $hierarchy->subjects();

        if ($subjects === []) {
            return AvailabilityMap::open();
        }

        $course ??= $this->database->selectOne(Query::from('courses')->where('id', '=', $courseId));
        if ($course !== null && $course['category_id'] !== null && $this->categoryVisibility !== null
            && !in_array((int) $course['category_id'], $this->categoryVisibility->visibleCategoryIds($principal->userId), true)) {
            $blocked = [];
            foreach ($subjects as $subject) {
                $blocked[$subject->key()] = new AvailabilityDecision(
                    \Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome::blocked('vidyalms.availability.category_access'),
                );
            }
            return new AvailabilityMap($blocked);
        }
        $context = $this->contextFor($principal, $course, $enrolment, $asOf);
        $trees = $this->treesFor($hierarchy, $subjects);

        // No stored tree still runs the grant pass. A grant is the last word
        // and an administrator excusing a learner from an item is exactly the
        // case where nothing else gates it, so returning early here would
        // make the one path that needs grants the one path that ignores them.
        $composed = $trees === [] ? [] : $this->composer->evaluateSet($trees, $context);

        if (($enrolment['status'] ?? null) === 'active'
            && !\Shondalai\VidyaLMS\Core\Application\Access\CoursePrerequisites::completed($this->database, $principal->userId, $courseId)) {
            $composed[(new GateSubject(GateSubject::TYPE_COURSE, $courseId))->key()] = new AvailabilityDecision(
                \Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome::blocked('vidyalms.availability.prerequisites'),
            );
        }

        // Grants run **before** inheritance and again after it, and both
        // passes are load-bearing. Before, so a grant on a blocked module
        // opens the module and its lessons inherit the open answer rather
        // than a copy of the block the grant just lifted; a deny on an
        // available module closes its lessons for the same reason. After, so
        // a grant written directly against a lesson under a blocked ancestor
        // is still the last word, which is what "the last word" means.
        $granted = $this->applyGrants($principal, $hierarchy, $composed, $context->now, $courseId, $enrolment);
        $inherited = $this->inherit($hierarchy, $granted);

        return new AvailabilityMap(
            $this->applyGrants($principal, $hierarchy, $inherited, $context->now, $courseId, $enrolment),
        );
    }

    /**
     * One subject, for a caller that holds no hierarchy: the enforcement path
     * inside a use case rather than the outline render.
     *
     * @param array<string, mixed>|null $enrolment
     */
    public function forSubject(
        Principal $principal,
        GateSubject $subject,
        int $courseId,
        ?array $enrolment = null,
        ?GateSubject $parent = null,
    ): AvailabilityDecision {
        $hierarchy = match ($subject->type) {
            GateSubject::TYPE_LESSON => $this->hierarchyFor($courseId, [$subject->id]),
            GateSubject::TYPE_QUIZ => $this->quizHierarchy($courseId, $subject->id),
            default => $this->flatHierarchy($courseId, $subject->type, [$subject->id]),
        };
        if ($parent !== null) {
            $hierarchy->add($subject, $parent);
        }

        return $this->forCourse($principal, $courseId, $hierarchy, $enrolment)->for($subject);
    }

    /**
     * The scope the completion recompute and the player reads pass down.
     *
     * It is built once per recompute and answers from one evaluation, so the
     * denominator and the numerator are read from the same id set and the
     * percentage can never exceed 100.
     *
     * @param array<string, mixed>|null $course
     * @param array<string, mixed>|null $enrolment
     */
    public function scopeFor(Principal $principal, ?array $course, ?array $enrolment): ?AvailabilityScope
    {
        $enrolmentId = $enrolment['id'] ?? null;

        if (!\is_numeric($enrolmentId)) {
            return null;
        }

        // The course row is what carries the id every scoped read narrows by,
        // so a caller that has the enrolment and not the course gets it
        // loaded rather than a scope that silently answers "no course, no
        // restrictions". That was every write path: the scope was built, the
        // engine was asked, and it opened everything.
        $courseId = $enrolment['course_id'] ?? null;

        if ($course === null && \is_numeric($courseId)) {
            $course = $this->database->selectOne(Query::from('courses')->where('id', '=', (int) $courseId));
        }

        return new AvailabilityScope(
            (int) $enrolmentId,
            // The learner's own context and never the caller's. A manager
            // recomputing somebody else's progress must produce the same
            // denominator the learner would, or a support action would
            // rewrite the number it was called to inspect.
            $this->contextFor($this->learnerOf($enrolment, $principal), $course, $enrolment, null),
            $this,
        );
    }

    /**
     * @param array<string, mixed>|null $enrolment
     */
    private function learnerOf(?array $enrolment, Principal $fallback): Principal
    {
        if ($enrolment === null) {
            return $fallback;
        }

        $userId = $enrolment['user_id'] ?? null;

        return \is_numeric($userId) && (int) $userId > 0 ? Principal::user((int) $userId) : $fallback;
    }

    /**
     * The denominator rule applied to a set of ids of one type.
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function countingIds(AvailabilityContext $context, string $subjectType, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $courseId = $context->courseId();

        if ($courseId === null) {
            return $ids;
        }

        $hierarchy = $subjectType === GateSubject::TYPE_LESSON
            ? $this->hierarchyFor($courseId, $ids)
            : $this->flatHierarchy($courseId, $subjectType, $ids);

        $map = $this->forCourse(
            $context->principal,
            $courseId,
            $hierarchy,
            $context->enrolment,
            $context->course,
            $context->now,
        );

        return $map->countingIds($subjectType, $ids);
    }

    /**
     * The entry question for one subject, asked from an existing scope.
     *
     * The whole hierarchy for that one subject is evaluated, so an ancestor
     * still answers for its descendants: a closed module refuses every lesson
     * inside it without the caller naming the module.
     */
    public function entryDecision(AvailabilityContext $context, string $subjectType, int $id): AvailabilityDecision
    {
        $courseId = $context->courseId();

        if ($courseId === null) {
            return AvailabilityDecision::open();
        }

        $hierarchy = match ($subjectType) {
            GateSubject::TYPE_LESSON => $this->hierarchyFor($courseId, [$id]),
            // A quiz hangs on a lesson, and a closed module never evaluates
            // its lesson trees but does answer for them. Asked flat, a quiz
            // inside a drip-locked lesson had no ancestor to inherit from and
            // started, which is the enforcement hole read one level up.
            GateSubject::TYPE_QUIZ => $this->quizHierarchy($courseId, $id),
            default => $this->flatHierarchy($courseId, $subjectType, [$id]),
        };

        return $this->forCourse(
            $context->principal,
            $courseId,
            $hierarchy,
            $context->enrolment,
            $context->course,
            $context->now,
        )->for(new GateSubject($subjectType, $id));
    }

    /**
     * The lesson hierarchy for a scoped read: the course, the modules holding
     * these lessons, the lessons themselves, and the sequencing flags. One
     * read whatever the item count.
     *
     * @param list<int> $lessonIds
     */
    public function hierarchyFor(int $courseId, array $lessonIds): GateHierarchy
    {
        $hierarchy = new GateHierarchy();
        $course = new GateSubject(GateSubject::TYPE_COURSE, $courseId);
        $hierarchy->add($course);

        if ($lessonIds === []) {
            return $hierarchy;
        }

        $rows = $this->database->selectAll(
            Query::from('lessons', 'l')
                ->select([
                    'l.id',
                    'l.module_id',
                    'l.requires_previous',
                    'l.state AS lesson_state',
                    'm.state AS module_state',
                    'm.progression AS module_progression',
                    'c.progression AS course_progression',
                ])
                ->join('modules', 'm', 'l.module_id', 'm.id')
                ->join('courses', 'c', 'l.course_id', 'c.id')
                ->where('l.course_id', '=', $courseId)
                ->orderBy('m.position')
                ->orderBy('l.position')
                ->orderBy('l.id'),
        );

        $publishedBefore = false;
        foreach ($rows as $row) {
            $hasPrevious = $publishedBefore;
            if ((string) $row['lesson_state'] === 'published' && (string) $row['module_state'] === 'published') {
                $publishedBefore = true;
            }
            if (!in_array((int) $row['id'], $lessonIds, true)) {
                continue;
            }
            $module = new GateSubject(GateSubject::TYPE_MODULE, (int) $row['module_id']);
            $hierarchy->add($module, $course);

            $lesson = new GateSubject(GateSubject::TYPE_LESSON, (int) $row['id']);
            $hierarchy->add($lesson, $module);

            $requiresPrevious = self::lessonRequiresPrevious(
                ['progression' => $row['course_progression'] ?? null],
                ['progression' => $row['module_progression'] ?? null],
                ['requires_previous' => $row['requires_previous'] ?? 0],
            );

            if ($requiresPrevious && $hasPrevious) {
                $hierarchy->requirePrevious($lesson);
            }
        }

        return $hierarchy;
    }

    /**
     * @param list<int> $ids
     */
    /**
     * One quiz under its own lesson, so the lesson's restriction and its
     * module's answer for it.
     *
     * A quiz with no lesson (a standalone assessment) is a direct child of
     * the course, which is what the flat shape already gives it.
     */
    private function quizHierarchy(int $courseId, int $quizId): GateHierarchy
    {
        $row = $this->database->selectOne(
            Query::from('quizzes')->select(['lesson_id'])->where('id', '=', $quizId),
        );

        $lessonId = $row['lesson_id'] ?? null;

        if (!\is_numeric($lessonId) || (int) $lessonId < 1) {
            return $this->flatHierarchy($courseId, GateSubject::TYPE_QUIZ, [$quizId]);
        }

        $hierarchy = $this->hierarchyFor($courseId, [(int) $lessonId]);
        $hierarchy->add(
            new GateSubject(GateSubject::TYPE_QUIZ, $quizId),
            new GateSubject(GateSubject::TYPE_LESSON, (int) $lessonId),
        );

        return $hierarchy;
    }

    /**
     * @param list<int> $ids
     */
    private function flatHierarchy(int $courseId, string $subjectType, array $ids): GateHierarchy
    {
        $hierarchy = new GateHierarchy();
        $course = new GateSubject(GateSubject::TYPE_COURSE, $courseId);
        $hierarchy->add($course);

        foreach ($ids as $id) {
            $hierarchy->add(new GateSubject($subjectType, $id), $course);
        }

        return $hierarchy;
    }

    /**
     * The trees to compose, stored plus the implicit sequencing leaf. A
     * subject with neither is omitted, which is what makes a course with no
     * rules cost nothing.
     *
     * @param array<string, GateSubject> $subjects
     *
     * @return array<string, SubjectTree>
     */
    private function treesFor(GateHierarchy $hierarchy, array $subjects): array
    {
        $rulesByType = [];

        foreach (GateSubject::types() as $type) {
            $ids = $hierarchy->idsOfType($type);

            if ($ids === []) {
                continue;
            }

            $rulesByType[$type] = $this->rules->forSubjects($type, $ids, AvailabilitySurface::ContentGating);
        }

        $trees = [];

        foreach ($subjects as $key => $subject) {
            $rule = $rulesByType[$subject->type][$subject->id] ?? null;
            $root = $rule?->tree->root;
            $children = [];
            $showc = [];

            if ($root !== null && $root->children !== []) {
                $children[] = $root;
                $showc[] = !($rule?->hidesByDefault() ?? false);
            }

            if ($hierarchy->requiresPrevious($subject)) {
                $children[] = new AvailabilityLeaf(CompletionCondition::KEY, [
                    'target' => ['kind' => $subject->type, 'id' => CompletionCondition::TARGET_PREVIOUS],
                    'state' => CompletionCondition::STATE_COMPLETE,
                ]);
                $showc[] = true;
            }

            if ($children === []) {
                continue;
            }

            $trees[$key] = new SubjectTree(
                new AvailabilityTree(new AvailabilityNode(AvailabilityNode::OP_AND, $children, true, $showc)),
                $subject,
            );
        }

        return $trees;
    }

    /**
     * Ancestors first: a blocked module answers for every lesson inside it,
     * and the reason the learner sees is the ancestor's rather than a child
     * rule the ancestor kept them from ever reaching.
     *
     * @param array<string, AvailabilityDecision> $composed
     *
     * @return array<string, AvailabilityDecision>
     */
    private function inherit(GateHierarchy $hierarchy, array $composed): array
    {
        $resolved = [];

        foreach ($hierarchy->subjects() as $key => $subject) {
            $decision = $composed[$key] ?? AvailabilityDecision::open();

            foreach ($hierarchy->ancestorsOf($subject) as $ancestor) {
                $ancestorDecision = $composed[$ancestor->key()] ?? null;

                if ($ancestorDecision === null || $ancestorDecision->isAvailable()) {
                    continue;
                }

                $decision = $ancestorDecision;
                break;
            }

            $resolved[$key] = $decision;
        }

        return $resolved;
    }

    /**
     * The last word. One row is the answer per scope, narrowest scope wins,
     * and within a scope a denial beats a grant because the narrower row is
     * the deliberate one.
     *
     * @param array<string, AvailabilityDecision> $decisions
     * @param array<string, mixed>|null           $enrolment
     *
     * @return array<string, AvailabilityDecision>
     */
    private function applyGrants(
        Principal $principal,
        GateHierarchy $hierarchy,
        array $decisions,
        \DateTimeImmutable $now,
        int $courseId = 0,
        ?array $enrolment = null,
    ): array {
        if ($principal->isGuest()) {
            return $decisions;
        }

        $types = [];

        foreach ($hierarchy->subjects() as $subject) {
            $types[$subject->type] = true;
        }

        $rows = $this->grants->forLearner($principal->userId, array_keys($types));

        if ($rows === []) {
            return $decisions;
        }

        $cohortId = $this->cohortOf($enrolment);

        /** @var array<string, AvailabilityGrant> $winners */
        $winners = [];

        foreach ($rows as $grant) {
            if (!$grant->isActiveAt($now)) {
                continue;
            }

            // A scope that does not contain this learner does not apply, and
            // ranking before checking is how a cohort-scoped row naming a run
            // the learner has never been in became the last word: cohort is
            // the narrowest scope, so the phantom row beat the course grant
            // and took the item out of the completion denominator.
            if (!$this->scopeApplies($grant, $courseId, $cohortId)) {
                $this->logger->notice('VidyaLMS availability grant skipped: its scope does not contain the learner.', [
                    'grant' => $grant->id,
                    'scope_type' => $grant->scopeType,
                    'scope_id' => $grant->scopeId,
                    'user_id' => $principal->userId,
                ]);
                continue;
            }

            $key = $grant->subject->key();
            $incumbent = $winners[$key] ?? null;

            if ($incumbent === null) {
                $winners[$key] = $grant;
                continue;
            }

            if ($grant->specificity() < $incumbent->specificity()) {
                $winners[$key] = $grant;
                continue;
            }

            if ($grant->specificity() === $incumbent->specificity() && $grant->isDeny() && !$incumbent->isDeny()) {
                $winners[$key] = $grant;
            }
        }

        foreach ($winners as $key => $grant) {
            if (!isset($hierarchy->subjects()[$key])) {
                continue;
            }

            $decisions[$key] = new AvailabilityDecision($grant->outcome(), true);
        }

        return $decisions;
    }

    /**
     * @param array<string, mixed>|null $course
     * @param array<string, mixed>|null $enrolment
     */
    private function contextFor(
        Principal $principal,
        ?array $course,
        ?array $enrolment,
        ?\DateTimeImmutable $asOf,
    ): AvailabilityContext {
        $courseTimezone = $course['timezone'] ?? null;
        $chain = [];

        if (\is_string($courseTimezone) && $courseTimezone !== '') {
            $chain[] = $courseTimezone;
        }

        $chain[] = $this->siteTimezone;

        return AvailabilityContext::create(
            $principal,
            AvailabilitySurface::ContentGating,
            $asOf ?? $this->clock->now(),
            $enrolment,
            $course,
            null,
            $chain,
        );
    }

    /**
     * Whether a lesson requires the one before it, which is the two-column
     * sequencing axis: a course-level enum, an optional per-module override
     * and a per-lesson flag.
     *
     * @param array<string, mixed>|null $course
     * @param array<string, mixed>|null $module
     * @param array<string, mixed>      $lesson
     */
    public static function lessonRequiresPrevious(?array $course, ?array $module, array $lesson): bool
    {
        if ((int) ($lesson['requires_previous'] ?? 0) === 1) {
            return true;
        }

        $moduleProgression = $module['progression'] ?? null;

        if (\is_string($moduleProgression) && $moduleProgression !== '') {
            return $moduleProgression === self::PROGRESSION_LINEAR;
        }

        return (string) ($course['progression'] ?? self::PROGRESSION_FREE) === self::PROGRESSION_LINEAR;
    }

    /**
     * @return list<string>
     */
    public static function progressions(): array
    {
        return [self::PROGRESSION_LINEAR, self::PROGRESSION_FREE];
    }

    /**
     * @return list<string>
     */
    public static function visibilities(): array
    {
        return AvailabilityRule::visibilities();
    }
}
