<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer;

use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
use Shondalai\VidyaLMS\Core\Application\Assessment\AddQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\CreateQuiz;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuiz;
use Shondalai\VidyaLMS\Core\Application\Assignments\ConfigureAssignment;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddModule;
use Shondalai\VidyaLMS\Core\Application\Curriculum\CreateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\MoveLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\PublishCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ReorderLessons;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ReorderModules;
use Shondalai\VidyaLMS\Core\Application\Curriculum\SetPrerequisites;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateModule;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportMap;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportProblem;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Importer\AbstractImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\ConfigurableImporterInterface;
use Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceSchemes;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;

/**
 * Moves a Community Quiz site's courses into VidyaLMS.
 *
 * Community Quiz is a Joomla component from the same vendor, and its tables
 * sit in the same database as the adapter, so this is a database import: the
 * source is the "cms-db:" scheme rather than an uploaded file, and every row
 * is read through DatabasePort as a host table. Nothing is written except
 * through the same use cases the authoring UI calls.
 *
 * ## The source
 *
 *   cms-db:                       every Community Quiz course
 *   cms-db:courses=12,15          those two courses
 *   cms-db:categories=3,7         every course in those Joomla categories
 *
 * ## Options
 *
 *   table_prefix  the Community Quiz prefix inside the host database,
 *                 default "cq_". The core may not assume the name of a table
 *                 it does not own, so the adapter passes this; the host's own
 *                 prefix ("#__") is the adapter's business and never appears
 *                 here.
 *   enrolments    import cq_enrollments as entitlements. Off by default, and
 *                 deliberately: enrolling a learner emits LearnerEnrolled,
 *                 which mails them a welcome for a course they joined two
 *                 years ago. Turn it on once you have decided that is what
 *                 you want, and re-run: the import map makes the second run
 *                 an update.
 *   publish       publish the courses that were published in Community Quiz.
 *                 Off by default, because a migration is worth a human's eye
 *                 before learners see it, and because a course whose harder
 *                 questions need Pro is not the course it was.
 *
 * ## What comes across, and what does not
 *
 * Courses, modules, lessons, quizzes and questions map onto the free
 * providers wherever a free provider can carry them. Everything that cannot
 * is counted in the totals as skipped and named in the log: the question
 * types that belong to Pro, the lesson types with no free equivalent, drip,
 * governance, certificate templates, blueprints, rubrics, accommodations and
 * random question pools.
 *
 * Learner history is the deliberate omission. Enrolments come across as
 * entitlements, which is a statement about access and true today. Lesson
 * progress and quiz attempts do not, because there is no honest way to write
 * them: the progress path belongs to the learner, and replaying it would date
 * every completion to the import, fire LessonCompleted and CourseCompleted
 * events that never happened then, and issue certificates today for work done
 * years ago. A migrated learner with the right access and no invented history
 * is worth more than one with a fabricated one, so the log says how many rows
 * were left behind and why.
 *
 * ## Identity
 *
 * Everything is recorded in the import map against Community Quiz's own ids,
 * under the table prefix the source was read with ("cq_|course:8"), because
 * one database can hold two Community Quiz installs and their ids both start
 * at 1. Quizzes and questions are keyed under the course that owns the copy
 * ("cq_|course:8/quiz:3"), because a Community Quiz quiz can be shared by
 * several courses while a VidyaLMS quiz belongs to exactly one: each course
 * gets its own copy, and each copy keeps its own identity across re-runs.
 *
 * A lesson that moved to another module of the same course moves here too on
 * the next run. One that moved to another course does not: the core has no
 * move that crosses courses, because a lesson carries the course id that
 * progress and attempts are written against, and the note says so.
 *
 * A module that moved to another Community Quiz course is the same shape and
 * is refused the same way. It is not updated in the course it is already in
 * and its lessons are not written into that course either: both would put
 * this run's content under a course the source no longer names. The note says
 * which module it is, which course holds it here and which course Community
 * Quiz keeps it in now, because moving it is a human's decision.
 *
 * ## Nothing here may end the run
 *
 * Every row this importer reads is foreign data, and a source value that
 * reaches a typed parameter throws a TypeError rather than a DomainException:
 * an Error is not caught by a handler that names DomainException, so one
 * nonsense row used to end the whole migration. Every per row boundary
 * catches Throwable, and the mapping validates before it converts. A skip is
 * always a note; the run continues.
 *
 * @phpstan-type QuizFields array{title: string, description: string|null, pass_mark: int,
 *     max_attempts: int|null, time_limit_seconds: int|null, is_final: bool, show_results: string}
 * @phpstan-type ImportedQuiz array{id: int, fields: QuizFields}
 */
final class CommunityQuizImporter extends AbstractImporter implements
    ConfigurableImporterInterface,
    DeclaresSourceSchemes
{
    public const KEY = 'community-quiz';

    /** The source scheme this importer answers to. */
    public const SCHEME = 'cms-db:';

    private const DEFAULT_TABLE_PREFIX = 'cq_';

    /** What Community Quiz calls the things, for the import map. */
    private const SOURCE_COURSE = 'course';
    private const SOURCE_MODULE = 'module';
    private const SOURCE_LESSON = 'lesson';
    private const SOURCE_QUIZ = 'quiz';
    private const SOURCE_QUESTION = 'question';
    private const SOURCE_ENROLMENT = 'enrollment';

    /** What the core calls them. */
    private const LOCAL_COURSE = 'course';
    private const LOCAL_MODULE = 'module';
    private const LOCAL_LESSON = 'lesson';
    private const LOCAL_QUIZ = 'quiz';
    private const LOCAL_QUESTION = 'question';
    private const LOCAL_ENTITLEMENT = 'entitlement';

    /** Sections that hold explicit question placements. */
    private const QUESTION_SECTIONS = ['fixed', 'case'];

    /** How much of a source type name a note quotes. */
    private const TYPE_EXCERPT = 40;

    /** How much of an import map key a note quotes. */
    private const KEY_EXCERPT = 80;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ImportMap $map,
        private readonly TranslatorPort $translator,
        private readonly CreateCourse $createCourse,
        private readonly UpdateCourse $updateCourse,
        private readonly PublishCourse $publishCourse,
        private readonly SetPrerequisites $setPrerequisites,
        private readonly AddModule $addModule,
        private readonly UpdateModule $updateModule,
        private readonly ReorderModules $reorderModules,
        private readonly AddLesson $addLesson,
        private readonly UpdateLesson $updateLesson,
        private readonly MoveLesson $moveLesson,
        private readonly ReorderLessons $reorderLessons,
        private readonly CreateQuiz $createQuiz,
        private readonly UpdateQuiz $updateQuiz,
        private readonly AddQuestion $addQuestion,
        private readonly UpdateQuestion $updateQuestion,
        private readonly ConfigureAssignment $configureAssignment,
        private readonly GrantEntitlement $grantEntitlement,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.importer.community_quiz');
    }

    /**
     * @return list<ImportOption>
     */
    public function importOptions(): array
    {
        return [
            ImportOption::boolean(
                'enrolments',
                $this->translator->translate('vidyalms.importer.option.enrolments'),
                $this->translator->translate('vidyalms.importer.option.enrolments.help'),
            ),
            ImportOption::boolean(
                'publish',
                $this->translator->translate('vidyalms.importer.option.publish'),
                $this->translator->translate('vidyalms.importer.option.publish.help'),
            ),
            new ImportOption(
                'table_prefix',
                $this->translator->translate('vidyalms.importer.option.table_prefix'),
                ImportOption::KIND_TEXT,
                $this->translator->translate('vidyalms.importer.option.table_prefix.help'),
                self::DEFAULT_TABLE_PREFIX,
            ),
        ];
    }

    public function supports(string $sourcePath): bool
    {
        return str_starts_with(strtolower(trim($sourcePath)), self::SCHEME);
    }

    /**
     * The scheme this importer answers to, said out loud for the screens and
     * for the published catalogue. Both hosts probed this literal, which was
     * right for these importers and wrong for the three that read another
     * product's tables; declaring it makes the assumption a contract.
     *
     * @return list<string>
     */
    public function sourceSchemes(): array
    {
        return [self::SCHEME];
    }

    public function importWithProgress(
        string $sourcePath,
        array $options,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        if (!$this->supports($sourcePath)) {
            throw new \RuntimeException(sprintf(
                '"%s" is not a Community Quiz source. Expected "%s", optionally with a selector such as "%scourses=12,15".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $sourcePath,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::SCHEME,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::SCHEME,
            ));
        }

        $run = new CommunityQuizRun(
            new CommunityQuizSource($this->database, $this->tablePrefix($options)),
            $principal,
            $progress,
            publish: ($options['publish'] ?? false) === true,
            enrolments: ($options['enrolments'] ?? false) === true,
        );

        [$courseIds, $categoryIds] = $this->selector($sourcePath);
        $courses = $run->source->courses($courseIds, $categoryIds);

        if ($courses === []) {
            $run->note('No Community Quiz courses matched this source.');

            return;
        }

        foreach ($courses as $course) {
            try {
                $this->course($run, $course);
            } catch (\Throwable $failure) {
                // The last boundary. Everything inside has its own, so
                // arriving here means the source broke in a way no mapping
                // anticipated: that course is lost, and the ones after it are
                // not.
                $run->failed(sprintf(
                    'Community Quiz course %d ("%s") could not be imported: %s',
                    (int) ($course['id'] ?? 0),
                    $this->shown($course['title'] ?? null),
                    ImportProblem::describe($failure),
                ));
            }
        }

        $this->prerequisites($run);
        $this->summarise($run);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function tablePrefix(array $options): string
    {
        $prefix = CommunityQuizMapping::text($options['table_prefix'] ?? null);

        return $prefix === '' ? self::DEFAULT_TABLE_PREFIX : $prefix;
    }

    /**
     * Reads the selector after the scheme. An unreadable one fails the whole
     * import on purpose: importing every course on the site because a filter
     * was misspelled is worse than being told.
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private function selector(string $sourcePath): array
    {
        $selector = trim(substr(trim($sourcePath), \strlen(self::SCHEME)));

        if ($selector === '') {
            return [[], []];
        }

        $courses = [];
        $categories = [];

        foreach (explode('&', $selector) as $pair) {
            $parts = explode('=', $pair, 2);
            $name = strtolower(trim($parts[0]));

            if ($name !== 'courses' && $name !== 'categories') {
                throw new \RuntimeException(sprintf(
                    'Unknown Community Quiz selector "%s". Use "courses" or "categories".',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $name,
                ));
            }

            $values = $this->ids($parts[1] ?? '');

            if ($values === []) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \RuntimeException(sprintf('"%s" names no ids.', $pair));
            }

            if ($name === 'courses') {
                $courses = [...$courses, ...$values];
            } else {
                $categories = [...$categories, ...$values];
            }
        }

        return [$courses, $categories];
    }

    /**
     * @return list<int>
     */
    private function ids(string $values): array
    {
        $ids = [];

        foreach (explode(',', $values) as $value) {
            $value = trim($value);

            if ($value === '') {
                continue;
            }

            if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \RuntimeException(sprintf('"%s" is not a Community Quiz id.', $value));
            }

            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed> $row a cq_courses row
     */
    private function course(CommunityQuizRun $run, array $row): void
    {
        $sourceId = (int) ($row['id'] ?? 0);

        if ($sourceId < 1) {
            $run->skipped('A Community Quiz course row carries no id and was not imported.');

            return;
        }

        $title = CommunityQuizMapping::text($row['title'] ?? null);

        if ($title === '') {
            $title = sprintf('Community Quiz course %d', $sourceId);
        }

        $completion = CommunityQuizMapping::decode($row['completion'] ?? null);
        $accessModel = CommunityQuizMapping::text($row['access_model'] ?? null);
        $membersOnly = $accessModel === 'members';
        $courseId = $this->saveCourse($run, $row, $sourceId, $title, $completion, $accessModel);

        if ($courseId === null) {
            return;
        }

        $run->rememberCourse($sourceId, $courseId);
        $this->courseFeatureNotes($run, $row, $title, $membersOnly);

        $lessons = $run->source->lessons($sourceId);
        $quizzes = $this->quizzes($run, $sourceId, $courseId, $lessons, $completion);
        $quizLessons = [];
        $this->modules($run, $sourceId, $courseId, $title, $lessons, $quizzes, $quizLessons);
        $this->finaliseQuizzes($run, $quizzes, $quizLessons, $lessons);
        $this->enrolments($run, $sourceId, $courseId, $title);
        $this->history($run, $sourceId, array_keys($quizzes));
        $this->publish($run, $row, $courseId, $title, $membersOnly);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $completion
     *
     * @return int|null the local course id, 0 in a dry run, null when the
     *                  course could not be written at all
     */
    private function saveCourse(
        CommunityQuizRun $run,
        array $row,
        int $sourceId,
        string $title,
        array $completion,
        string $accessModel,
    ): ?int {
        $rule = CommunityQuizMapping::completion($completion);
        $label = $this->shown($title);
        $language = CommunityQuizMapping::text($row['language'] ?? null);
        $summary = $this->nullable($row['subtitle'] ?? null);
        $description = $this->nullable($row['description'] ?? null);
        $level = $this->nullable($row['level'] ?? null);
        $language = $language === '' || $language === '*' ? null : $language;
        $visibility = CommunityQuizMapping::visibility($row);
        // "members" is a Joomla access level, which the free build has no gate
        // for, so a members-only course lands free and unpublished rather than
        // paid and unreachable. courseFeatureNotes() says so, and publish()
        // refuses to publish it.
        $priceMode = $accessModel === 'paid' ? 'paid' : 'free';
        $changes = [
            'title' => $title,
            'summary' => $summary,
            'description' => $description,
            'level' => $level,
            'language' => $language,
            'visibility' => $visibility,
            'price_mode' => $priceMode,
            'completion_rule' => $rule['rule'],
            'completion_threshold' => $rule['threshold'],
        ];

        $existing = $this->map->lookup(self::KEY, self::SOURCE_COURSE, $this->courseKey($run, $sourceId));

        if ($run->isDryRun()) {
            $run->counted(ImportResult::TOTAL_COURSES);
            $run->note(sprintf('Course "%s" would be %s.', $label, $existing === null ? 'created' : 'updated'));

            return 0;
        }

        try {
            if ($existing !== null) {
                $this->updateCourse->execute($run->principal, $existing, $changes);
                $courseId = $existing;
            } else {
                $alias = CommunityQuizMapping::text($row['alias'] ?? null);
                $courseId = $this->createCourse->execute(
                    $run->principal,
                    $title,
                    slug: $alias === '' ? null : $alias,
                    summary: $summary,
                    description: $description,
                    level: $level,
                    language: $language,
                    visibility: $visibility,
                    priceMode: $priceMode,
                    completionRule: $rule['rule'],
                    completionThreshold: $rule['threshold'],
                )->id;
            }
        } catch (\Throwable $failure) {
            $run->skipped(sprintf('Course "%s" was not imported: %s', $label, ImportProblem::describe($failure)));

            return null;
        }

        $this->remember($run, self::SOURCE_COURSE, $this->courseKey($run, $sourceId), self::LOCAL_COURSE, $courseId);
        $run->counted(ImportResult::TOTAL_COURSES);
        $run->note(sprintf('Course "%s" %s.', $label, $existing === null ? 'created' : 'updated'));

        return $courseId;
    }

    /**
     * Everything Community Quiz configured on this course that the free build
     * has no home for. Each note names the course and the feature, so the
     * site owner knows exactly what to rebuild rather than discovering it
     * from a learner.
     *
     * @param array<string, mixed> $row
     */
    private function courseFeatureNotes(CommunityQuizRun $run, array $row, string $title, bool $membersOnly): void
    {
        $title = $this->shown($title);
        $drip = CommunityQuizMapping::decode($row['drip'] ?? null);

        if (($drip['enabled'] ?? false) === true) {
            $run->warn(sprintf(
                'Course "%s": content drip did not come across. Scheduled release is a Pro capability.',
                $title,
            ));
        }

        if (array_filter(CommunityQuizMapping::decode($row['governance'] ?? null)) !== []) {
            $run->warn(sprintf(
                'Course "%s": governance settings (lock lessons after publish, approvals) did not come across. They are a Pro capability.',
                $title,
            ));
        }

        $certConfig = CommunityQuizMapping::decode($row['cert_config'] ?? null);

        if ((int) ($row['cert_template_id'] ?? 0) > 0 || array_filter($certConfig) !== []) {
            $run->warn(sprintf(
                'Course "%s": its certificate template and settings did not come across.'
                    . ' The free build issues its own default certificate.',
                $title,
            ));
        }

        if ($membersOnly) {
            $run->warn(sprintf(
                'Course "%s" was restricted to a Joomla access level. The free build has no membership'
                    . ' gate, so it is imported as a free course and is not published by this import.',
                $title,
            ));
        }

        if ((int) ($row['catid'] ?? 0) > 0) {
            $run->tally('categorised');
        }

        if ((int) ($row['state'] ?? 0) === 1 && !$run->publish) {
            $run->tally('published-in-source');
        }
    }

    /**
     * The quizzes this course's lessons point at, each imported as a copy
     * belonging to this course.
     *
     * @param list<array<string, mixed>> $lessons
     * @param array<string, mixed>       $completion
     *
     * @return array<int, ImportedQuiz>
     */
    private function quizzes(
        CommunityQuizRun $run,
        int $sourceCourseId,
        int $courseId,
        array $lessons,
        array $completion,
    ): array {
        $finalQuizId = (int) ($completion['final_quiz_id'] ?? 0);
        $imported = [];

        foreach ($lessons as $lesson) {
            $sourceQuizId = (int) ($lesson['quiz_id'] ?? 0);

            if ($sourceQuizId < 1 || isset($imported[$sourceQuizId])) {
                continue;
            }

            $row = $run->source->quiz($sourceQuizId);

            if ($row === null) {
                $run->skipped(sprintf(
                    'Lesson "%s" points at Community Quiz quiz %d, which is not in the database.',
                    $this->shown($lesson['title'] ?? null),
                    $sourceQuizId,
                ));
                continue;
            }

            $quiz = $this->quiz($run, $sourceCourseId, $courseId, $row, $finalQuizId === $sourceQuizId);

            if ($quiz !== null) {
                $imported[$sourceQuizId] = $quiz;
            }
        }

        return $imported;
    }

    /**
     * @param array<string, mixed> $row a cq_quizzes row
     *
     * @return ImportedQuiz|null
     */
    private function quiz(
        CommunityQuizRun $run,
        int $sourceCourseId,
        int $courseId,
        array $row,
        bool $isFinal,
    ): ?array {
        $sourceQuizId = (int) ($row['id'] ?? 0);
        $title = CommunityQuizMapping::text($row['title'] ?? null);

        if ($title === '') {
            $title = sprintf('Community Quiz quiz %d', $sourceQuizId);
        }

        $label = $this->shown($title);
        $showResults = CommunityQuizMapping::showResults(CommunityQuizMapping::decode($row['feedback'] ?? null));
        $attempts = (int) ($row['attempts_allowed'] ?? 1);
        $description = $this->nullable($row['intro'] ?? null);

        $fields = [
            'title' => $title,
            'description' => $description,
            'pass_mark' => CommunityQuizMapping::passMark($row),
            'max_attempts' => $attempts > 0 ? $attempts : null,
            // Community Quiz stores the limit in minutes, and a source
            // number too large to be seconds is no limit rather than an
            // overflow the typed parameter would reject.
            'time_limit_seconds' => CommunityQuizMapping::minutesAsSeconds($row['duration'] ?? null),
            'is_final' => $isFinal,
            'show_results' => $showResults['value'],
        ];

        $sourceKey = $this->quizKey($run, $sourceCourseId, $sourceQuizId);
        $existing = $this->map->lookup(self::KEY, self::SOURCE_QUIZ, $sourceKey);

        if ($run->isDryRun()) {
            $run->counted(ImportResult::TOTAL_QUIZZES);
            $run->note(sprintf('Quiz "%s" would be %s.', $label, $existing === null ? 'created' : 'updated'));
            $this->questions($run, $sourceCourseId, $sourceQuizId, 0, $label);

            return ['id' => 0, 'fields' => $fields];
        }

        try {
            $quizId = $existing ?? $this->createQuiz->execute(
                $run->principal,
                $courseId,
                $title,
                description: $fields['description'],
                passMark: $fields['pass_mark'],
                maxAttempts: $fields['max_attempts'],
                timeLimitSeconds: $fields['time_limit_seconds'],
                isFinal: $isFinal,
                showResults: $fields['show_results'],
            )->id;
        } catch (\Throwable $failure) {
            $run->skipped(sprintf('Quiz "%s" was not imported: %s', $label, ImportProblem::describe($failure)));

            return null;
        }

        if ($quizId === null) {
            $run->skipped(sprintf('Quiz "%s" was stored without an id and was not imported.', $label));

            return null;
        }

        $this->remember($run, self::SOURCE_QUIZ, $sourceKey, self::LOCAL_QUIZ, $quizId);
        $run->counted(ImportResult::TOTAL_QUIZZES);
        $run->note(sprintf('Quiz "%s" %s.', $label, $existing === null ? 'created' : 'updated'));
        $this->quizFeatureNotes($run, $row, $label, $sourceQuizId, $showResults['note']);
        $this->questions($run, $sourceCourseId, $sourceQuizId, $quizId, $label);

        return ['id' => $quizId, 'fields' => $fields];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function quizFeatureNotes(
        CommunityQuizRun $run,
        array $row,
        string $title,
        int $sourceQuizId,
        ?string $resultsNote,
    ): void {
        if ($resultsNote !== null) {
            $run->warn(sprintf('Quiz "%s" %s.', $title, $resultsNote));
        }

        if (CommunityQuizMapping::text($row['kind'] ?? null) === 'survey') {
            $run->warn(sprintf(
                'Quiz "%s" is a survey. The free build has no ungraded survey mode, so it comes across as a quiz.',
                $title,
            ));
        }

        if ($run->source->blueprintCount($sourceQuizId) > 0) {
            $run->warn(sprintf(
                'Quiz "%s": its test blueprint did not come across. Blueprints are a Pro capability.',
                $title,
            ));
        }

        $accommodations = $run->source->accommodationCount($sourceQuizId);

        if ($accommodations > 0) {
            $run->warn(sprintf(
                'Quiz "%s": %d accommodation(s) did not come across. Per-learner exam adjustments are a Pro capability.',
                $title,
                $accommodations,
            ));
        }
    }

    /**
     * The questions of one quiz, in the order the quiz shows them: sections
     * in their order, placements in theirs.
     */
    private function questions(
        CommunityQuizRun $run,
        int $sourceCourseId,
        int $sourceQuizId,
        int $quizId,
        string $quizTitle,
    ): void {
        $converted = [];
        $placed = [];

        foreach ($run->source->sections($sourceQuizId) as $section) {
            $sectionType = CommunityQuizMapping::text($section['type'] ?? null);
            $items = $run->source->sectionItems((int) ($section['id'] ?? 0));
            // A section the free build cannot carry takes its questions with
            // it, and the totals count them: "one row skipped" for a section
            // hiding forty questions tells a Quiz customer nothing about how
            // much of their exam needs Pro. A section holding no placement of
            // its own still counts as the one thing that was left behind.
            $hidden = max(1, \count($items));

            if ($sectionType === 'random') {
                $run->warn(sprintf(
                    'Quiz "%s" draws part of itself at random from a question bank, which the free'
                        . ' build has no equivalent of. That section was not imported.',
                    $quizTitle,
                ));
                $run->counted(ImportResult::TOTAL_SKIPPED, $hidden);
                continue;
            }

            if (!\in_array($sectionType, self::QUESTION_SECTIONS, true)) {
                $run->tally('section:' . $sectionType);
                $run->tally('section-question', \count($items));
                $run->counted(ImportResult::TOTAL_SKIPPED, $hidden);
                continue;
            }

            foreach ($items as $item) {
                $sourceQuestionId = (int) ($item['question_id'] ?? 0);

                // One question placed twice in the same quiz is one question
                // here: a second copy would share the first's identity and
                // overwrite it on the next run.
                if (isset($placed[$sourceQuestionId])) {
                    $run->skippedQuietly('duplicate-placement');
                    continue;
                }

                $placed[$sourceQuestionId] = true;
                $this->question($run, $sourceCourseId, $sourceQuizId, $quizId, $quizTitle, $item, $converted);
            }
        }
    }

    /**
     * @param array<string, mixed> $item      a cq_section_items row
     * @param array<string, bool>  $converted the conversion notes this quiz has already made
     */
    private function question(
        CommunityQuizRun $run,
        int $sourceCourseId,
        int $sourceQuizId,
        int $quizId,
        string $quizTitle,
        array $item,
        array &$converted,
    ): void {
        $sourceQuestionId = (int) ($item['question_id'] ?? 0);
        $row = $sourceQuestionId > 0 ? $run->source->question($sourceQuestionId) : null;

        if ($row === null) {
            $run->skipped(sprintf(
                'Quiz "%s" places question %d, which is not in the database.',
                $quizTitle,
                $sourceQuestionId,
            ));

            return;
        }

        $sourceType = CommunityQuizMapping::text($row['type'] ?? null);

        if (!CommunityQuizMapping::supportsQuestion($sourceType)) {
            // Named by the alias the rest of the product knows it by, rather
            // than by a legacy word only this file has. An alias whose preset
            // compiles to a type Pro registers has nothing here to land on,
            // because filling a definition is a binding's job and every
            // binding is a Pro provider, so the item is counted and named as
            // skipped exactly as any other unresolvable type is.
            $run->skippedQuietly('question:' . (CommunityQuizMapping::sourceAlias($sourceType) ?? $sourceType));

            return;
        }

        $override = $item['points_override'] ?? null;

        try {
            $mapped = CommunityQuizMapping::question($row, is_numeric($override) ? (float) $override : null);
        } catch (\Throwable $failure) {
            $run->skipped(sprintf(
                'Question %d of quiz "%s" was not imported: %s.',
                $sourceQuestionId,
                $quizTitle,
                ImportProblem::describe($failure),
            ));

            return;
        }

        if ((int) ($row['rubric_id'] ?? 0) > 0) {
            $run->tally('rubric');
        }

        $note = $mapped['note'];

        if ($note !== null && !isset($converted[$sourceType . $note])) {
            $converted[$sourceType . $note] = true;
            $run->warn(sprintf(
                'Quiz "%s": each "%s" question came across changed, %s.',
                $quizTitle,
                $this->shown($sourceType, self::TYPE_EXCERPT),
                $note,
            ));
        }

        if ($run->isDryRun()) {
            $run->counted(ImportResult::TOTAL_QUESTIONS);

            return;
        }

        $sourceKey = $this->questionKey($run, $sourceCourseId, $sourceQuizId, $sourceQuestionId);
        $existing = $this->map->lookup(self::KEY, self::SOURCE_QUESTION, $sourceKey);

        try {
            $questionId = $existing !== null
                ? $this->updateQuestion->execute($run->principal, $existing, $mapped['definition'], $mapped['points'])->id
                : $this->addQuestion->execute($run->principal, $quizId, $mapped['type'], $mapped['definition'], $mapped['points'])->id;
        } catch (\Throwable $failure) {
            $run->skipped(sprintf(
                'Question %d of quiz "%s" was not imported: %s',
                $sourceQuestionId,
                $quizTitle,
                ImportProblem::describe($failure),
            ));

            return;
        }

        if ($questionId === null) {
            return;
        }

        $this->remember($run, self::SOURCE_QUESTION, $sourceKey, self::LOCAL_QUESTION, $questionId);
        $run->counted(ImportResult::TOTAL_QUESTIONS);
    }

    /**
     * @param list<array<string, mixed>>                          $lessons
     * @param array<int, ImportedQuiz> $quizzes
     * @param array<int, int>                                     $quizLessons source quiz id to the lesson hosting it
     */
    private function modules(
        CommunityQuizRun $run,
        int $sourceCourseId,
        int $courseId,
        string $courseTitle,
        array $lessons,
        array $quizzes,
        array &$quizLessons,
    ): void {
        $byModule = [];

        foreach ($lessons as $lesson) {
            $byModule[(int) ($lesson['module_id'] ?? 0)][] = $lesson;
        }

        $moduleIds = [];
        $known = [];
        $ordering = [];

        foreach ($run->source->modules($sourceCourseId) as $module) {
            $sourceModuleId = (int) ($module['id'] ?? 0);
            $known[$sourceModuleId] = true;
            $title = CommunityQuizMapping::text($module['title'] ?? null);

            if ($title === '') {
                $title = sprintf('Module %d', $sourceModuleId);
            }

            $lessonsOfModule = $byModule[$sourceModuleId] ?? [];
            $moduleId = $this->saveModule(
                $run,
                $module,
                $sourceModuleId,
                $courseId,
                $courseTitle,
                $title,
                \count($lessonsOfModule),
            );

            if ($moduleId === null) {
                // The module's own skip is already noted; its lessons go with
                // it, and the totals have to say so.
                $run->skippedQuietly('lesson-of-skipped-module', \count($lessonsOfModule));
                continue;
            }

            $lessonIds = $this->lessons($run, $moduleId, $lessonsOfModule, $quizzes, $quizLessons);

            if (!$run->isDryRun()) {
                $moduleIds[] = $moduleId;
                $ordering[$moduleId] = $lessonIds;
            }
        }

        foreach ($byModule as $sourceModuleId => $orphans) {
            if (!isset($known[$sourceModuleId])) {
                $run->skippedQuietly('orphan-lesson', \count($orphans));
            }
        }

        // Ordering waits until every module of the course has been through
        // the loop above, because a lesson that moved between two of them is
        // in neither list until the module it moved to has been written. A
        // reorder run halfway would fail on a list that is complete a moment
        // later, and report a curriculum problem that does not exist.
        foreach ($ordering as $moduleId => $lessonIds) {
            $this->reorder($run, fn () => $this->reorderLessons->execute($run->principal, $moduleId, $lessonIds));
        }

        if ($moduleIds !== []) {
            $this->reorder($run, fn () => $this->reorderModules->execute($run->principal, $courseId, $moduleIds));
        }
    }

    /**
     * @param array<string, mixed> $module a cq_course_modules row
     */
    private function saveModule(
        CommunityQuizRun $run,
        array $module,
        int $sourceModuleId,
        int $courseId,
        string $courseTitle,
        string $title,
        int $lessonCount,
    ): ?int {
        $summary = $this->nullable($module['summary'] ?? null);
        $label = $this->shown($title);
        $existing = $this->map->lookup(self::KEY, self::SOURCE_MODULE, $this->moduleKey($run, $sourceModuleId));

        if ($run->isDryRun()) {
            $run->counted(ImportResult::TOTAL_MODULES);

            return 0;
        }

        try {
            if ($existing !== null) {
                $updated = $this->updateModule->execute($run->principal, $existing, ['title' => $title, 'summary' => $summary]);

                if ($updated->courseId !== $courseId) {
                    $this->movedModule($run, $updated->courseId, $courseTitle, $label, $lessonCount);

                    return null;
                }

                $moduleId = $updated->id;
            } else {
                $moduleId = $this->addModule->execute($run->principal, $courseId, $title, $summary)->id;
            }
        } catch (\Throwable $failure) {
            $run->skipped(sprintf('Module "%s" was not imported: %s', $label, ImportProblem::describe($failure)));

            return null;
        }

        $this->remember($run, self::SOURCE_MODULE, $this->moduleKey($run, $sourceModuleId), self::LOCAL_MODULE, $moduleId);
        $run->counted(ImportResult::TOTAL_MODULES);
        $run->note(sprintf('Module "%s" %s.', $label, $existing === null ? 'created' : 'updated'));

        return $moduleId;
    }

    /**
     * The module this import already brought in under another course, which
     * Community Quiz now keeps in this one.
     *
     * The core has no move for it. A lesson can cross modules of one course
     * (MoveLesson), but a module carries the course its lessons, progress and
     * attempts belong to, so changing it is a delete and a re-create and that
     * is a person's decision. Importing this course's lessons into the module
     * where it stands would be worse than doing nothing: they would land
     * under a course the source no longer names, silently. So nothing is
     * written and the note says exactly where the module is, where the source
     * wants it, and what it costs.
     *
     * The caller counts the module's lessons as left behind.
     */
    private function movedModule(
        CommunityQuizRun $run,
        int $heldByCourseId,
        string $courseTitle,
        string $label,
        int $lessonCount,
    ): void {
        $run->warn(sprintf(
            'Module "%s" belongs to Community Quiz course "%s" now, but this import brought it in under'
                . ' %s and a module cannot change course here: its lessons carry the course that learner'
                . ' progress and attempts are written against. It was left where it is, and the %d lesson(s)'
                . ' it holds were not imported into "%s". Move them by hand, or delete the module here and'
                . ' re-run to have it imported into its current course.',
            $label,
            $this->shown($courseTitle),
            $this->courseNamed($heldByCourseId),
            $lessonCount,
            $this->shown($courseTitle),
        ));
        $run->counted(ImportResult::TOTAL_SKIPPED);
    }

    /**
     * How a note names the course a local module sits in: the Community Quiz
     * course it came from when this importer brought it in, and the local id
     * otherwise.
     */
    private function courseNamed(int $courseId): string
    {
        $entry = $this->map->provenanceOf(self::LOCAL_COURSE, $courseId);

        return $entry === null || $entry->importer !== self::KEY
            ? sprintf('VidyaLMS course %d', $courseId)
            : sprintf('the course imported as "%s"', $this->shown($entry->sourceKey, self::KEY_EXCERPT));
    }

    /**
     * @param list<array<string, mixed>>                               $rows
     * @param array<int, ImportedQuiz> $quizzes
     * @param array<int, int>                                          $quizLessons
     *
     * @return list<int>
     */
    private function lessons(
        CommunityQuizRun $run,
        int $moduleId,
        array $rows,
        array $quizzes,
        array &$quizLessons,
    ): array {
        $lessonIds = [];

        foreach ($rows as $row) {
            $sourceLessonId = (int) ($row['id'] ?? 0);
            $title = CommunityQuizMapping::text($row['title'] ?? null);

            if ($title === '') {
                $title = sprintf('Lesson %d', $sourceLessonId);
            }

            $sourceType = CommunityQuizMapping::text($row['type'] ?? null);

            if (!CommunityQuizMapping::supportsLesson($sourceType)) {
                $run->skippedQuietly('lesson:' . $sourceType);
                continue;
            }

            $sourceQuizId = (int) ($row['quiz_id'] ?? 0);
            $quizId = $sourceType === 'quiz' ? ($quizzes[$sourceQuizId]['id'] ?? null) : null;

            try {
                $mapped = CommunityQuizMapping::lesson($row, $quizId);
            } catch (\Throwable $failure) {
                $run->skipped(sprintf(
                    'Lesson "%s" was not imported: %s.',
                    $this->shown($title),
                    ImportProblem::describe($failure),
                ));
                continue;
            }

            if ($run->isDryRun()) {
                $run->counted(ImportResult::TOTAL_LESSONS);
                continue;
            }

            $lessonId = $this->saveLesson($run, $row, $sourceLessonId, $moduleId, $title, $mapped);

            if ($lessonId === null) {
                continue;
            }

            $lessonIds[] = $lessonId;

            if ($sourceType === 'quiz' && $sourceQuizId > 0) {
                $quizLessons[$sourceQuizId] = $lessonId;
            }
        }

        return $lessonIds;
    }

    /**
     * @param array<string, mixed>                                                   $row
     * @param array{type: string, content: array<string, mixed>, note: string|null}  $mapped
     */
    private function saveLesson(
        CommunityQuizRun $run,
        array $row,
        int $sourceLessonId,
        int $moduleId,
        string $title,
        array $mapped,
    ): ?int {
        $duration = CommunityQuizMapping::durationSeconds($row['duration'] ?? null);
        $label = $this->shown($title);
        $freePreview = (int) ($row['is_preview'] ?? 0) === 1;
        $sourceKey = $this->lessonKey($run, $sourceLessonId);
        $existing = $this->map->lookup(self::KEY, self::SOURCE_LESSON, $sourceKey);

        try {
            if ($existing !== null) {
                $lesson = $this->updateLesson->execute($run->principal, $existing, [
                    'title' => $title,
                    'duration_seconds' => $duration,
                    'free_preview' => $freePreview,
                    'type' => $mapped['type'],
                    'content' => $mapped['content'],
                ] + ($mapped['type'] === 'video' && ($duration ?? 0) < 1 ? ['state' => 'draft'] : []));

                // UpdateLesson leaves the module alone, so the lesson it
                // returns still names the module of the last run.
                if ($lesson->moduleId !== $moduleId && !$this->move($run, $lesson->id, $moduleId, $label)) {
                    return null;
                }

                $lessonId = $lesson->id;
            } else {
                $lessonId = $this->addLesson->execute(
                    $run->principal,
                    $moduleId,
                    $title,
                    $mapped['type'],
                    $mapped['content'],
                    durationSeconds: $duration,
                    freePreview: $freePreview,
                    state: $mapped['type'] === 'video' && ($duration ?? 0) < 1 ? 'draft' : 'published',
                )->id;
            }
        } catch (\Throwable $failure) {
            $run->skipped(sprintf('Lesson "%s" was not imported: %s', $label, ImportProblem::describe($failure)));

            return null;
        }

        $this->remember($run, self::SOURCE_LESSON, $sourceKey, self::LOCAL_LESSON, $lessonId);
        $run->counted(ImportResult::TOTAL_LESSONS);
        $run->note(sprintf('Lesson "%s" %s.', $label, $existing === null ? 'created' : 'updated'));

        if ($mapped['note'] !== null) {
            $run->warn(sprintf('Lesson "%s": %s.', $label, $mapped['note']));
        }

        if ($mapped['type'] === 'assignment') {
            $this->configureAssignmentLesson($run, $row, $lessonId, $label);
        }

        return $lessonId;
    }

    /**
     * Puts an already imported lesson under the module it belongs to now.
     *
     * A curriculum gets reorganised, and a lesson that moved in Community
     * Quiz has to move here too: left where it was, it is missing from the
     * module the source says holds it and present in one the source no longer
     * does, and the reorder that follows would report the difference as an
     * ordering problem rather than as the lost lesson it is.
     *
     * @return bool false when the lesson stayed where it was, which is when
     *              the caller must leave it out of the new module's order
     */
    private function move(CommunityQuizRun $run, int $lessonId, int $moduleId, string $title): bool
    {
        try {
            $this->moveLesson->execute($run->principal, $lessonId, $moduleId);
        } catch (\Throwable $failure) {
            // The move the core cannot make is one between courses: a lesson
            // carries its course id, and progress, attempts and submissions
            // are written against it. Say that, rather than counting it as
            // one more thing out of order.
            $run->warn(sprintf(
                'Lesson "%s" belongs to a different module in Community Quiz now and was left where it is: %s'
                    . ' Delete it here and re-run to have it imported into its current course.',
                $title,
                ImportProblem::describe($failure),
            ));

            return false;
        }

        $run->note(sprintf('Lesson "%s" moved to the module Community Quiz now keeps it in.', $title));

        return true;
    }

    /**
     * The submission rules of an assignment lesson, which live beside the
     * lesson in the Assignments context rather than in its content.
     *
     * @param array<string, mixed> $row
     */
    private function configureAssignmentLesson(CommunityQuizRun $run, array $row, int $lessonId, string $title): void
    {
        $content = CommunityQuizMapping::decode($row['content'] ?? null);
        $maxPoints = (float) ($content['max_points'] ?? 100);
        $maxFiles = (int) ($content['max_files'] ?? 1);
        $textOnly = CommunityQuizMapping::text($content['submission_mode'] ?? null) === 'text';

        try {
            $this->configureAssignment->execute(
                $run->principal,
                $lessonId,
                instructions: CommunityQuizMapping::text($content['prompt'] ?? null),
                maxPoints: $maxPoints > 0 ? $maxPoints : 100.0,
                maxFiles: max(1, $maxFiles),
                allowResubmission: ($content['allow_resubmission'] ?? true) !== false,
            );
        } catch (\Throwable $failure) {
            $run->warn(sprintf(
                'Lesson "%s": its submission rules did not come across: %s',
                $title,
                ImportProblem::describe($failure),
            ));

            return;
        }

        if ($textOnly) {
            $run->warn(sprintf(
                'Lesson "%s" accepted text only. The free build always allows an attachment as well.',
                $title,
            ));
        }
    }

    /**
     * Writes each imported quiz's settings and points it at the lesson that
     * hosts it. This cannot happen earlier, because the lesson does not exist
     * until the quiz has an id, and it is where an already imported quiz
     * takes its update.
     *
     * It only ever acts on what it can establish. A quiz is unbound from a
     * lesson when the source no longer gives it one; a quiz whose lesson this
     * run did not write keeps the lesson the import map still names, because
     * "this run did not create it" and "it is not there" are different facts.
     * They came apart when a module failed on a re-run: its lessons were not
     * touched, they were still on the site, and unbinding their quizzes broke
     * courses that nothing had actually changed.
     *
     * @param array<int, ImportedQuiz>   $quizzes
     * @param array<int, int>            $quizLessons source quiz id to the lesson this run wrote for it
     * @param list<array<string, mixed>> $lessons     the source lesson rows of this course
     */
    private function finaliseQuizzes(CommunityQuizRun $run, array $quizzes, array $quizLessons, array $lessons): void
    {
        if ($run->isDryRun()) {
            return;
        }

        $hosts = $this->quizHosts($run, $lessons);

        foreach ($quizzes as $sourceQuizId => $quiz) {
            if ($quiz['id'] < 1) {
                continue;
            }

            $fields = $quiz['fields'];
            $lessonId = $quizLessons[$sourceQuizId] ?? $hosts[$sourceQuizId] ?? null;

            try {
                $this->updateQuiz->execute(
                    $run->principal,
                    $quiz['id'],
                    $fields['title'],
                    lessonId: $lessonId,
                    description: $fields['description'],
                    passMark: $fields['pass_mark'],
                    maxAttempts: $fields['max_attempts'],
                    timeLimitSeconds: $fields['time_limit_seconds'],
                    isFinal: $fields['is_final'],
                    showResults: $fields['show_results'],
                );
            } catch (\Throwable $failure) {
                $run->warn(sprintf(
                    'Quiz "%s" kept its previous settings: %s',
                    $this->shown($fields['title']),
                    ImportProblem::describe($failure),
                ));
            }
        }
    }

    /**
     * The lesson each quiz of this course is hosted by according to the
     * source and the import map together: the source says which lesson holds
     * the quiz, and the map says which local lesson that is. It is what a
     * quiz keeps when this run did not get as far as writing its lesson.
     *
     * @param list<array<string, mixed>> $lessons
     *
     * @return array<int, int> source quiz id to local lesson id
     */
    private function quizHosts(CommunityQuizRun $run, array $lessons): array
    {
        $hosts = [];

        foreach ($lessons as $lesson) {
            $sourceQuizId = (int) ($lesson['quiz_id'] ?? 0);
            $sourceLessonId = (int) ($lesson['id'] ?? 0);

            if ($sourceQuizId < 1 || $sourceLessonId < 1 || isset($hosts[$sourceQuizId])) {
                continue;
            }

            if (CommunityQuizMapping::text($lesson['type'] ?? null) !== 'quiz') {
                continue;
            }

            $lessonId = $this->map->lookup(self::KEY, self::SOURCE_LESSON, $this->lessonKey($run, $sourceLessonId));

            if ($lessonId !== null) {
                $hosts[$sourceQuizId] = $lessonId;
            }
        }

        return $hosts;
    }

    /**
     * Community Quiz enrolments become entitlements granted by this import,
     * keyed by the original enrolment id so a second run updates rather than
     * grants twice.
     */
    private function enrolments(CommunityQuizRun $run, int $sourceCourseId, int $courseId, string $courseTitle): void
    {
        $rows = $run->source->enrolments($sourceCourseId);
        $courseTitle = $this->shown($courseTitle);

        if ($rows === []) {
            return;
        }

        if (!$run->enrolments) {
            $run->warn(sprintf(
                'Course "%s" has %d Community Quiz enrolment(s), which were not imported. Enrolments are'
                    . ' imported only when the "enrolments" option asks for them, because enrolling a learner'
                    . ' emails them a welcome. Re-run with it on when you are ready.',
                $courseTitle,
                \count($rows),
            ));
            $run->counted(ImportResult::TOTAL_SKIPPED, \count($rows));

            return;
        }

        foreach ($rows as $index => $row) {
            $sourceId = (int) ($row['id'] ?? 0);
            $userId = (int) ($row['user_id'] ?? 0);

            if ($sourceId < 1 || $userId < 1) {
                $run->skippedQuietly('enrolment-without-user');
                continue;
            }

            if ($run->isDryRun()) {
                $run->counted(ImportResult::TOTAL_ENROLMENTS);
                continue;
            }

            try {
                $entitlement = $this->grantEntitlement->execute(
                    $run->principal,
                    $userId,
                    $courseId,
                    Entitlement::SOURCE_IMPORT,
                    sourceRef: $this->enrolmentRef($run, $sourceId),
                    expiresAt: $this->datetime($row['expires_at'] ?? null),
                );
            } catch (NotAuthorizedException $failure) {
                $remaining = \count($rows) - $index;
                $run->warn(sprintf(
                    'The account running this import may not enrol learners, so %d enrolment(s) of course "%s" were not imported: %s',
                    $remaining,
                    $courseTitle,
                    ImportProblem::describe($failure),
                ));
                $run->counted(ImportResult::TOTAL_SKIPPED, $remaining);

                return;
            } catch (\Throwable $failure) {
                $run->skipped(sprintf(
                    'Enrolment %d of course "%s" was not imported: %s',
                    $sourceId,
                    $courseTitle,
                    ImportProblem::describe($failure),
                ));
                continue;
            }

            $this->remember(
                $run,
                self::SOURCE_ENROLMENT,
                $this->enrolmentKey($run, $sourceId),
                self::LOCAL_ENTITLEMENT,
                $entitlement->id(),
            );
            $run->counted(ImportResult::TOTAL_ENROLMENTS);
        }
    }

    /**
     * The learner history this import leaves behind, counted now and
     * explained once at the end of the run.
     *
     * @param list<int> $sourceQuizIds
     */
    private function history(CommunityQuizRun $run, int $sourceCourseId, array $sourceQuizIds): void
    {
        $progress = $run->source->lessonProgressCount($sourceCourseId);

        if ($progress > 0) {
            $run->tally('progress', $progress);
            $run->counted(ImportResult::TOTAL_SKIPPED, $progress);
        }

        foreach ($sourceQuizIds as $sourceQuizId) {
            $attempts = $run->source->attemptCount($sourceQuizId);

            if ($attempts > 0) {
                $run->tally('attempts', $attempts);
                $run->counted(ImportResult::TOTAL_SKIPPED, $attempts);
            }
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function publish(CommunityQuizRun $run, array $row, int $courseId, string $title, bool $membersOnly): void
    {
        if ($run->isDryRun() || !$run->publish || $courseId < 1 || (int) ($row['state'] ?? 0) !== 1) {
            return;
        }

        $title = $this->shown($title);

        if ($membersOnly) {
            $run->warn(sprintf(
                'Course "%s" was published in Community Quiz but is left as a draft here, because its'
                    . ' membership restriction did not come across and publishing it would open it to everyone.',
                $title,
            ));

            return;
        }

        try {
            $this->publishCourse->execute($run->principal, $courseId);
            $run->note(sprintf('Course "%s" published, as it was in Community Quiz.', $title));
        } catch (\Throwable $failure) {
            $run->warn(sprintf('Course "%s" could not be published: %s', $title, ImportProblem::describe($failure)));
        }
    }

    /**
     * Course prerequisites, once every course of the run has an id. A
     * prerequisite pointing at a course this run did not import cannot be
     * expressed, and an exam prerequisite has no free equivalent at all.
     */
    private function prerequisites(CommunityQuizRun $run): void
    {
        if ($run->isDryRun()) {
            return;
        }

        foreach ($run->importedCourses() as $sourceCourseId => $courseId) {
            $rows = $run->source->prerequisites($sourceCourseId);

            if ($rows === []) {
                continue;
            }

            $required = [];

            foreach ($rows as $row) {
                if (CommunityQuizMapping::text($row['required_type'] ?? null) !== 'course') {
                    $run->tally('prerequisite-exam');
                    continue;
                }

                $target = $run->courseFor((int) ($row['required_id'] ?? 0));

                if ($target === null || $target === $courseId) {
                    $run->tally('prerequisite-unresolved');
                    continue;
                }

                $required[] = $target;
            }

            if ($required === []) {
                continue;
            }

            try {
                $this->setPrerequisites->execute($run->principal, $courseId, array_values(array_unique($required)));
            } catch (\Throwable $failure) {
                $run->warn(sprintf('The prerequisites of course %d were not set: %s', $courseId, ImportProblem::describe($failure)));
            }
        }
    }

    /**
     * The closing summary: everything counted per kind rather than per row,
     * so a migration of two hundred hotspot questions writes one line instead
     * of two hundred.
     */
    private function summarise(CommunityQuizRun $run): void
    {
        foreach ($run->tallies('question:') as $type => $count) {
            $run->warn(sprintf(
                '%d "%s" question(s) were not imported: that question type is a Pro capability.',
                $count,
                $this->shown($type, self::TYPE_EXCERPT),
            ));
        }

        foreach ($run->tallies('lesson:') as $type => $count) {
            $run->warn(sprintf(
                '%d "%s" lesson(s) were not imported: that lesson type has no free equivalent.',
                $count,
                $this->shown($type, self::TYPE_EXCERPT),
            ));
        }

        foreach ($run->tallies('section:') as $type => $count) {
            $run->warn(sprintf(
                '%d quiz section(s) of type "%s" were not imported: the free build has no section that carries them.',
                $count,
                $this->shown($type, self::TYPE_EXCERPT),
            ));
        }

        $this->summariseOne(
            $run,
            'section-question',
            '%d question(s) sat inside a quiz section that did not come across, and were not imported'
                . ' either. They need the Pro section that carries them.',
        );
        $this->summariseOne($run, 'orphan-lesson', '%d lesson(s) belong to no module and were not imported.');
        $this->summariseOne(
            $run,
            'duplicate-placement',
            '%d question(s) were placed more than once in the same quiz and were imported once.',
        );
        $this->summariseOne($run, 'lesson-of-skipped-module', '%d lesson(s) went with a module that could not be imported.');
        $this->summariseOne(
            $run,
            'rubric',
            '%d question(s) were graded against a rubric in Community Quiz. Rubric grading is a Pro'
                . ' capability, so they came across as ordinary questions.',
        );
        $this->summariseOne(
            $run,
            'categorised',
            '%d course(s) were in a Joomla category. Categories are not carried across; set them here after the import.',
        );
        $this->summariseOne(
            $run,
            'published-in-source',
            '%d course(s) were published in Community Quiz and are drafts here. Review what came across,'
                . ' then publish them, or re-run with the "publish" option on.',
        );
        $this->summariseOne(
            $run,
            'enrolment-without-user',
            '%d enrolment(s) name no learner and were not imported.',
        );
        $this->summariseOne(
            $run,
            'unordered',
            '%d course(s) or module(s) keep the order the import created them in, because they also hold'
                . ' content this import did not create and only a complete list can be reordered.',
        );
        $this->summariseOne(
            $run,
            'prerequisite-exam',
            '%d prerequisite(s) require an exam rather than a course, which the free build cannot express.',
        );
        $this->summariseOne(
            $run,
            'prerequisite-unresolved',
            '%d prerequisite(s) point at a course this import did not bring in. Import those courses too and re-run to restore them.',
        );

        $progress = $run->tallied('progress');
        $attempts = $run->tallied('attempts');

        if ($progress > 0 || $attempts > 0) {
            $run->warn(sprintf(
                '%d lesson progress row(s) and %d quiz attempt(s) were left behind. Replaying learner'
                    . ' history would date every completion to this import, fire completion events that never'
                    . ' happened then and issue certificates today for work done long ago. Learners keep their'
                    . ' access and start from where the course says they are.',
                $progress,
                $attempts,
            ));
        }
    }

    private function summariseOne(CommunityQuizRun $run, string $tally, string $message): void
    {
        $count = $run->tallied($tally);

        if ($count > 0) {
            $run->warn(sprintf($message, $count));
        }
    }

    private function remember(CommunityQuizRun $run, string $sourceType, string $sourceKey, string $localType, int $localId): void
    {
        if ($run->isDryRun() || $localId < 1) {
            return;
        }

        $this->map->remember(self::KEY, $sourceType, $sourceKey, $localType, $localId, $run->jobId());
    }

    /**
     * Reordering needs the complete list of a course's modules or a module's
     * lessons, so it cannot run over a course that also holds locally
     * authored content. That is not a failure of the import: what came from
     * Community Quiz is already there, only its order is the one it was
     * created in.
     *
     * @param callable(): void $reorder
     */
    private function reorder(CommunityQuizRun $run, callable $reorder): void
    {
        try {
            $reorder();
        } catch (DomainException) {
            $run->tally('unordered');
        } catch (\Throwable $failure) {
            // Not the ordering problem the tally describes, and not worth the
            // run: say what happened and leave the order alone.
            $run->warn(sprintf('An imported course or module kept its order: %s', ImportProblem::describe($failure)));
        }
    }

    private function courseKey(CommunityQuizRun $run, int $sourceCourseId): string
    {
        return $this->scoped($run, sprintf('course:%d', $sourceCourseId));
    }

    private function moduleKey(CommunityQuizRun $run, int $sourceModuleId): string
    {
        return $this->scoped($run, sprintf('module:%d', $sourceModuleId));
    }

    private function lessonKey(CommunityQuizRun $run, int $sourceLessonId): string
    {
        return $this->scoped($run, sprintf('lesson:%d', $sourceLessonId));
    }

    private function quizKey(CommunityQuizRun $run, int $sourceCourseId, int $sourceQuizId): string
    {
        return $this->scoped($run, sprintf('course:%d/quiz:%d', $sourceCourseId, $sourceQuizId));
    }

    private function questionKey(
        CommunityQuizRun $run,
        int $sourceCourseId,
        int $sourceQuizId,
        int $sourceQuestionId,
    ): string {
        return $this->scoped(
            $run,
            sprintf('course:%d/quiz:%d/question:%d', $sourceCourseId, $sourceQuizId, $sourceQuestionId),
        );
    }

    /**
     * The import map key of one Community Quiz enrolment. It carries the
     * prefix for the same reason every other key does: enrolment 201 of one
     * install is not enrolment 201 of the other, and two installs sharing a
     * database would otherwise overwrite each other's provenance and make the
     * entitlement of one look like the entitlement of the other.
     */
    private function enrolmentKey(CommunityQuizRun $run, int $sourceEnrolmentId): string
    {
        return $this->scoped($run, sprintf('enrollment:%d', $sourceEnrolmentId));
    }

    private function enrolmentRef(CommunityQuizRun $run, int $sourceEnrolmentId): string
    {
        // The entitlement's source_ref is what makes a re-run re-grant
        // nothing, so it is the map key under this importer's name.
        return sprintf('%s:%s', self::KEY, $this->enrolmentKey($run, $sourceEnrolmentId));
    }

    /**
     * A source key that names which Community Quiz it came from.
     *
     * Two installs can share a Joomla database under two table prefixes, and
     * their ids start at 1 in both, so a key of "8" alone makes the second
     * import overwrite the first's course. The prefix is the identity of the
     * install and is part of every key.
     *
     * The selector deliberately is not. "cms-db:" and "cms-db:courses=8" are
     * two views of the same install, and keying by them would make an admin
     * who re-runs with a narrower filter import a second copy of everything
     * that filter still matches, which is the duplication the import map
     * exists to prevent.
     */
    private function scoped(CommunityQuizRun $run, string $key): string
    {
        return $run->source->prefix() . '|' . $key;
    }

    private function nullable(mixed $value): ?string
    {
        $text = CommunityQuizMapping::text($value);

        return $text === '' ? null : $text;
    }

    /**
     * A source value as a note may quote it. Community Quiz titles are free
     * text of any length, and the job log is rewritten whole on every
     * progress write and rendered whole by both admin screens, so the log
     * quotes an excerpt and the row keeps the whole of it.
     */
    private function shown(mixed $value, int $limit = ImportNote::EXCERPT_LIMIT): string
    {
        return ImportNote::excerpt(CommunityQuizMapping::text($value), $limit);
    }

    /**
     * A Community Quiz datetime the core will accept, or nothing. Zero dates
     * and anything else unparseable read as "no date", which is what they
     * mean.
     */
    private function datetime(mixed $value): ?string
    {
        $text = CommunityQuizMapping::text($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $text) !== 1) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $text, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $text ? $text : null;
    }
}
