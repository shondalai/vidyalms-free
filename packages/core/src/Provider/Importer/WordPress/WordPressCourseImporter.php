<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\WordPress;

use Shondalai\VidyaLMS\Core\Application\Access\EnrolLearner;
use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
use Shondalai\VidyaLMS\Core\Application\Assessment\AddQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\CreateQuiz;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuiz;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddModule;
use Shondalai\VidyaLMS\Core\Application\Curriculum\CreateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateModule;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportMap;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportProblem;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Progress\RecordLessonProgress;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\CourseDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\ItemDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\MembershipDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\QuestionDraft;
use Shondalai\VidyaLMS\Core\Provider\Importer\WordPress\Draft\SectionDraft;
use Shondalai\VidyaLMS\Core\Registry\Importer\AbstractImporter;
use Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceSchemes;
use Shondalai\VidyaLMS\Core\Registry\Importer\DeclaresSourceTypes;
use Shondalai\VidyaLMS\Core\Registry\Importer\HonoursUpsertPolicy;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportOption;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;
use Shondalai\VidyaLMS\Core\Registry\Importer\UpsertPolicy;

/**
 * The half of a WordPress LMS migration that is the same for every product:
 * the options, the source convention, the use case calls and the idempotency.
 *
 * Three importers in this wave read three foreign schemas and produce the same
 * thing, a `CourseDraft`. Everything downstream of that draft is here, once,
 * so a product reader contains all of its product's vocabulary and none of the
 * writing, and this class contains all of the writing and none of the
 * vocabulary. That is the same anti-corruption split the assessment item
 * mapper layer uses, and it is what stops three importers from inventing three
 * slightly different ideas of what a re-run means.
 *
 * ## Nothing here writes a table
 *
 * Every object is created through the use case the authoring UI calls, so an
 * imported course obeys every rule a hand built one does. There is one read of
 * a table we own, and it is the enrolment the grant just created, taken by
 * asking `EnrolLearner` again rather than by selecting a row: its contract is
 * already "return the active enrolment if there is one", so asking twice is
 * how a caller learns the id without reaching past a use case.
 *
 * ## Nothing here may end the run
 *
 * Every row read is foreign data, and a source value that reaches a typed
 * parameter throws a `TypeError` rather than a `DomainException`, which a
 * handler naming `DomainException` does not catch. Every per row boundary
 * catches `Throwable`, records a row problem and continues. A run ends only
 * when the product is not there at all.
 */
abstract class WordPressCourseImporter extends AbstractImporter implements
    DeclaresSourceSchemes,
    DeclaresSourceTypes,
    HonoursUpsertPolicy
{
    /** What the source system calls the things, for the import map. */
    protected const SOURCE_COURSE = 'course';
    protected const SOURCE_SECTION = 'section';
    protected const SOURCE_LESSON = 'lesson';
    protected const SOURCE_QUIZ = 'quiz';
    protected const SOURCE_QUESTION = 'question';
    protected const SOURCE_ENROLMENT = 'enrolment';

    /** What the core calls them. */
    protected const LOCAL_COURSE = 'course';
    protected const LOCAL_MODULE = 'module';
    protected const LOCAL_LESSON = 'lesson';
    protected const LOCAL_QUIZ = 'quiz';
    protected const LOCAL_QUESTION = 'question';
    protected const LOCAL_ENTITLEMENT = 'entitlement';

    /** The option that says which content to bring. */
    public const OPTION_CONTENT = 'content';
    public const CONTENT_WITH_QUIZZES = 'curriculum_and_quizzes';
    public const CONTENT_CURRICULUM = 'curriculum_only';

    /** The option that says whether learners come across, matching the shipped importer's name. */
    public const OPTION_LEARNERS = 'enrolments';

    /** The option that says whether what a learner finished comes across. */
    public const OPTION_PROGRESS = 'progress';

    /** The selector word every one of these importers accepts after its scheme. */
    protected const SELECTOR_COURSES = 'courses';

    public function __construct(
        protected readonly ImportMap $map,
        protected readonly TranslatorPort $translator,
        private readonly CreateCourse $createCourse,
        private readonly UpdateCourse $updateCourse,
        private readonly AddModule $addModule,
        private readonly UpdateModule $updateModule,
        private readonly AddLesson $addLesson,
        private readonly UpdateLesson $updateLesson,
        private readonly CreateQuiz $createQuiz,
        private readonly UpdateQuiz $updateQuiz,
        private readonly AddQuestion $addQuestion,
        private readonly UpdateQuestion $updateQuestion,
        private readonly GrantEntitlement $grantEntitlement,
        private readonly EnrolLearner $enrolLearner,
        private readonly RecordLessonProgress $recordLessonProgress,
    ) {
    }

    /**
     * The source scheme this importer answers to, ending in a colon.
     */
    abstract public function scheme(): string;

    /**
     * The scheme this importer answers to, said out loud for the screens.
     *
     * Both hosts used to probe the literal "cms-db:" to decide whether to
     * offer a database control, which is a string none of these three answers
     * to: a LearnDash importer must not claim the Community Quiz importer's
     * source string. So all three were offered an upload they refuse and no
     * database control at all, and neither shipped screen could start them.
     *
     * @return list<string>
     */
    public function sourceSchemes(): array
    {
        return [$this->scheme()];
    }

    /**
     * The product's own name, as it appears in a note an operator reads.
     */
    abstract protected function productName(): string;

    /**
     * The reader, which owns every table name and every foreign word.
     */
    abstract protected function source(): WordPressSource;

    /**
     * Why this product is not readable here, for the refusal it earns.
     */
    abstract protected function absenceReason(): string;

    /**
     * Reads the product and produces one draft per course the selector picked.
     *
     * @param list<int> $courseIds empty meaning every course
     *
     * @return list<CourseDraft>
     */
    abstract protected function read(array $courseIds, WordPressImportRun $run): array;

    /**
     * The three options every one of these importers declares, plus the
     * upsert policy. A product with an option of its own appends to this.
     *
     * `dry_run` is deliberately absent: the pipeline owns it, `ImportOption`
     * refuses it by name, and it is decided by which button the operator
     * pressed rather than by a control an importer draws.
     *
     * @return list<ImportOption>
     */
    public function importOptions(): array
    {
        return [
            ImportOption::choice(
                self::OPTION_CONTENT,
                $this->translator->translate('vidyalms.importer.option.content'),
                [
                    [
                        'value' => self::CONTENT_WITH_QUIZZES,
                        'label' => $this->translator->translate('vidyalms.importer.option.content.curriculum_and_quizzes'),
                    ],
                    [
                        'value' => self::CONTENT_CURRICULUM,
                        'label' => $this->translator->translate('vidyalms.importer.option.content.curriculum_only'),
                    ],
                ],
                $this->translator->translate('vidyalms.importer.option.content.help'),
                self::CONTENT_WITH_QUIZZES,
            ),
            ImportOption::boolean(
                self::OPTION_LEARNERS,
                $this->translator->translate('vidyalms.importer.option.enrolments'),
                $this->translator->translate('vidyalms.importer.option.enrolments.help'),
            ),
            ImportOption::boolean(
                self::OPTION_PROGRESS,
                $this->translator->translate('vidyalms.importer.option.progress'),
                $this->translator->translate('vidyalms.importer.option.progress.help'),
            ),
            UpsertPolicy::option(
                $this->translator->translate('vidyalms.importer.option.upsert'),
                [
                    UpsertPolicy::CREATE_ONLY =>
                        $this->translator->translate('vidyalms.importer.option.upsert.create_only'),
                    UpsertPolicy::CREATE_AND_UPDATE =>
                        $this->translator->translate('vidyalms.importer.option.upsert.create_and_update'),
                    UpsertPolicy::UPDATE_ONLY =>
                        $this->translator->translate('vidyalms.importer.option.upsert.update_only'),
                    UpsertPolicy::CREATE_WITH_SUFFIX =>
                        $this->translator->translate('vidyalms.importer.option.upsert.create_with_suffix'),
                ],
                $this->translator->translate('vidyalms.importer.option.upsert.help'),
            ),
        ];
    }

    /**
     * All four, honestly. Every object these importers write is identified in
     * the import map by the product's own id, so create_with_suffix has a real
     * meaning here: it copies rather than collides, and the map is re-pointed
     * at the newest copy.
     *
     * @return list<string>
     */
    public function upsertPolicies(): array
    {
        return UpsertPolicy::POLICIES;
    }

    /**
     * None of them reserved. A product migration mints courses, sections,
     * lessons, quizzes, questions and enrolments, which are this importer's own
     * words and carry no tenant rule: a WordPress site holds one install of one
     * plugin, so the product's own ids are already unique inside it.
     *
     * @return list<string>
     */
    public function sourceTypes(): array
    {
        return [
            self::SOURCE_COURSE,
            self::SOURCE_SECTION,
            self::SOURCE_LESSON,
            self::SOURCE_QUIZ,
            self::SOURCE_QUESTION,
            self::SOURCE_ENROLMENT,
        ];
    }

    /**
     * Whether this source is this importer's, which for a database source is
     * two questions: the scheme names this product, and this product is really
     * installed here.
     *
     * The second half is what makes the Import screen honest on a site that
     * has never had this product: the importer does not offer itself, and an
     * operator who names it anyway is refused by `import()` with the reason.
     */
    public function supports(string $sourcePath): bool
    {
        if (!str_starts_with(strtolower(trim($sourcePath)), $this->scheme())) {
            return false;
        }

        try {
            return $this->source()->isInstalled();
        } catch (\Throwable) {
            // Detection never fails a screen. A database that cannot answer is
            // a database with no such product on it, as far as this question
            // goes.
            return false;
        }
    }

    public function importWithProgress(
        string $sourcePath,
        array $options,
        Principal $principal,
        ImportProgress $progress,
    ): void {
        if (!str_starts_with(strtolower(trim($sourcePath)), $this->scheme())) {
            throw new \RuntimeException(sprintf(
                '"%s" is not a %s source. Expected "%s", optionally with a selector such as "%s%s=12,15".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $sourcePath,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->productName(),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->scheme(),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->scheme(),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::SELECTOR_COURSES,
            ));
        }

        if (!$this->source()->isInstalled()) {
            throw new \RuntimeException(sprintf(
                '%s is not installed on this site: %s. Nothing was written.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->productName(),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->absenceReason(),
            ));
        }

        $run = new WordPressImportRun(
            $principal,
            $progress,
            UpsertPolicy::of($options),
            quizzes: ($options[self::OPTION_CONTENT] ?? self::CONTENT_WITH_QUIZZES) !== self::CONTENT_CURRICULUM,
            learners: ($options[self::OPTION_LEARNERS] ?? false) === true,
            progressRows: ($options[self::OPTION_PROGRESS] ?? false) === true,
        );

        $run->warn(sprintf(
            'This %s importer was written from published schema documentation and has not been verified against a'
                . ' real %s install. Check the imported curriculum order, the question types listed as skipped, and'
                . ' every learner\'s access before you publish anything.',
            $this->productName(),
            $this->productName(),
        ));

        $drafts = $this->read($this->selector($sourcePath), $run);

        if ($drafts === []) {
            $run->note(sprintf('No %s courses matched this source.', $this->productName()));
            $this->summarise($run);

            return;
        }

        foreach ($drafts as $draft) {
            try {
                $this->write($run, $draft);
            } catch (\Throwable $failure) {
                // The last boundary. Everything inside has its own, so arriving
                // here means the source broke in a way no mapping anticipated:
                // that course is lost, and the ones after it are not.
                $run->problem(
                    self::SOURCE_COURSE,
                    self::rowOf($draft->sourceId),
                    null,
                    RowProblem::CODE_MISSING_REQUIRED,
                    ImportProblem::describe($failure),
                    $draft->title,
                    RowProblem::OUTCOME_FAILED,
                );
                $run->failed(sprintf(
                    '%s course %s ("%s") could not be imported: %s',
                    $this->productName(),
                    $draft->sourceId,
                    ImportNote::excerpt($draft->title),
                    ImportProblem::describe($failure),
                ));
            }
        }

        $this->summarise($run);
    }

    /**
     * The course ids after the scheme. An unreadable selector fails the whole
     * import on purpose: importing every course on the site because a filter
     * was misspelled is worse than being told.
     *
     * @return list<int>
     */
    protected function selector(string $sourcePath): array
    {
        $selector = trim(substr(trim($sourcePath), \strlen($this->scheme())));

        if ($selector === '') {
            return [];
        }

        $parts = explode('=', $selector, 2);

        if (\count($parts) !== 2 || strtolower(trim($parts[0])) !== self::SELECTOR_COURSES) {
            throw new \RuntimeException(sprintf(
                '"%s" is not a selector this importer understands. Use "%s%s=12,15" or nothing at all.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $selector,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $this->scheme(),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::SELECTOR_COURSES,
            ));
        }

        $ids = [];

        foreach (explode(',', $parts[1]) as $value) {
            $value = trim($value);

            if (preg_match('/^\d+$/', $value) !== 1 || (int) $value < 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \RuntimeException(sprintf('"%s" is not a course id.', ImportNote::excerpt($value)));
            }

            $ids[] = (int) $value;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Writes one course draft through the use cases, in curriculum order.
     */
    protected function write(WordPressImportRun $run, CourseDraft $draft): void
    {
        $courseId = $this->saveCourse($run, $draft);

        if ($courseId === null) {
            return;
        }

        foreach ($draft->sections as $section) {
            $moduleId = $this->saveSection($run, $draft, $section, $courseId);

            if ($moduleId === null) {
                // Every item under a section that could not be written is a
                // referential miss naming the section, never a retry.
                foreach ($section->items as $item) {
                    $run->problem(
                        $item->isQuiz() ? self::SOURCE_QUIZ : self::SOURCE_LESSON,
                        self::rowOf($item->sourceId),
                        null,
                        RowProblem::CODE_REFERENTIAL_MISS,
                        sprintf('Its section "%s" was not imported.', ImportNote::excerpt($section->title)),
                        $item->title,
                    );
                }

                continue;
            }

            foreach ($section->items as $item) {
                $this->saveItem($run, $draft, $item, $courseId, $moduleId);
            }
        }

        $this->saveMemberships($run, $draft, $courseId);
    }

    /**
     * @return int|null the local course id, 0 in a dry run, null when the
     *                  course was not written at all
     */
    private function saveCourse(WordPressImportRun $run, CourseDraft $draft): ?int
    {
        $existing = $this->map->lookup($this->key(), self::SOURCE_COURSE, $draft->sourceId);
        $decision = $this->decide($run, $existing);

        if ($decision === null) {
            $run->note(sprintf(
                'Course "%s" was left as it is, because this run is a %s.',
                ImportNote::excerpt($draft->title),
                $run->upsert,
            ));
            $run->rowOutcome(self::SOURCE_COURSE, RowProblem::OUTCOME_UNCHANGED);

            // The existing id and not null, which is the difference between
            // "leave this course alone" and "stop". Returning null made
            // write() give up before it reached a single section, so
            // create_only against a source that had gained a lesson created
            // nothing at all: the whole point of the policy, per
            // UpsertPolicy's own docblock, is "the choice for a file that adds
            // this term's intake to an existing roster". Under update_only the
            // id is null here, because there is nothing to write children
            // under, and giving up is right.
            return $existing;
        }

        $title = $decision === RowProblem::OUTCOME_CREATED && $existing !== null
            ? $this->suffixed($draft->title)
            : $draft->title;

        if ($run->isDryRun()) {
            $run->counted(ImportResult::TOTAL_COURSES);
            $run->note(sprintf('Course "%s" would be %s.', ImportNote::excerpt($title), $decision));

            return 0;
        }

        try {
            if ($decision === RowProblem::OUTCOME_UPDATED && $existing !== null) {
                $this->updateCourse->execute($run->principal, $existing, [
                    'title' => $title,
                    'summary' => $draft->summary,
                    'description' => $draft->description,
                ]);
                $courseId = $existing;
            } else {
                $courseId = $this->createCourse->execute(
                    $run->principal,
                    $title,
                    slug: $draft->slug,
                    summary: $draft->summary,
                    description: $draft->description,
                )->id;
            }
        } catch (\Throwable $failure) {
            $run->problem(
                self::SOURCE_COURSE,
                self::rowOf($draft->sourceId),
                'post_title',
                RowProblem::CODE_MISSING_REQUIRED,
                ImportProblem::describe($failure),
                $draft->title,
                RowProblem::OUTCOME_FAILED,
            );
            $run->skipped(sprintf(
                'Course "%s" was not imported: %s',
                ImportNote::excerpt($title),
                ImportProblem::describe($failure),
            ));

            return null;
        }

        $this->remember($run, self::SOURCE_COURSE, $draft->sourceId, self::LOCAL_COURSE, $courseId, $decision);
        $run->counted(ImportResult::TOTAL_COURSES);
        $run->note(sprintf('Course "%s" %s.', ImportNote::excerpt($title), $decision));

        return $courseId;
    }

    private function saveSection(
        WordPressImportRun $run,
        CourseDraft $draft,
        SectionDraft $section,
        int $courseId,
    ): ?int {
        $key = $this->scopedKey($draft->sourceId, $section->sourceId);
        $existing = $this->map->lookup($this->key(), self::SOURCE_SECTION, $key);
        $decision = $this->decide($run, $existing);

        if ($decision === null) {
            $run->rowOutcome(self::SOURCE_SECTION, RowProblem::OUTCOME_UNCHANGED);

            // The existing id, so a create_only run still reaches the lessons
            // this term added under a section that was already here. See
            // saveCourse() for the whole of the reasoning.
            return $existing;
        }

        if ($run->isDryRun()) {
            $run->counted(ImportResult::TOTAL_MODULES);

            return 0;
        }

        try {
            if ($decision === RowProblem::OUTCOME_UPDATED && $existing !== null) {
                $this->updateModule->execute($run->principal, $existing, [
                    'title' => $section->title,
                    'summary' => $section->summary,
                ]);
                $moduleId = $existing;
            } else {
                $moduleId = $this->addModule->execute($run->principal, $courseId, $section->title, $section->summary)->id;
            }
        } catch (\Throwable $failure) {
            $run->problem(
                self::SOURCE_SECTION,
                self::rowOf($section->sourceId),
                'post_title',
                RowProblem::CODE_MISSING_REQUIRED,
                ImportProblem::describe($failure),
                $section->title,
                RowProblem::OUTCOME_FAILED,
            );
            $run->skipped(sprintf(
                'Section "%s" was not imported: %s',
                ImportNote::excerpt($section->title),
                ImportProblem::describe($failure),
            ));

            return null;
        }

        $this->remember($run, self::SOURCE_SECTION, $key, self::LOCAL_MODULE, $moduleId, $decision);
        $run->counted(ImportResult::TOTAL_MODULES);

        return $moduleId;
    }

    private function saveItem(
        WordPressImportRun $run,
        CourseDraft $draft,
        ItemDraft $item,
        int $courseId,
        int $moduleId,
    ): void {
        if ($item->isQuiz() && !$run->quizzes) {
            $run->skippedQuietly('content:quiz');

            return;
        }

        $quizId = null;

        if ($item->isQuiz()) {
            $quizId = $this->saveQuiz($run, $draft, $item, $courseId);

            if ($quizId === null) {
                return;
            }
        }

        $lessonId = $this->saveLesson($run, $draft, $item, $moduleId, $quizId);

        if ($lessonId === null) {
            return;
        }

        $run->rememberLesson($item->sourceId, $lessonId);

        if (!$item->isQuiz() || $quizId === null || $quizId < 1 || $lessonId < 1) {
            return;
        }

        try {
            // The quiz has to exist before the lesson that hosts it, and the
            // lesson has to exist before the quiz can name it. One extra call
            // is what closes that circle, and it is the same order the
            // shipped Community Quiz importer uses.
            $this->updateQuiz->execute(
                $run->principal,
                $quizId,
                $item->title,
                lessonId: $lessonId,
                passMark: $item->passMark,
                maxAttempts: $item->maxAttempts,
                timeLimitSeconds: $item->timeLimit,
            );
        } catch (\Throwable $failure) {
            $run->warn(sprintf(
                'Quiz "%s" was imported but could not be attached to its lesson: %s',
                ImportNote::excerpt($item->title),
                ImportProblem::describe($failure),
            ));
        }

        foreach ($item->questions as $question) {
            $this->saveQuestion($run, $draft, $item, $question, $quizId);
        }
    }

    /**
     * @return int|null the local quiz id, 0 in a dry run, null when it was not
     *                  written
     */
    private function saveQuiz(WordPressImportRun $run, CourseDraft $draft, ItemDraft $item, int $courseId): ?int
    {
        $key = $this->scopedKey($draft->sourceId, $item->sourceId);
        $existing = $this->map->lookup($this->key(), self::SOURCE_QUIZ, $key);
        $decision = $this->decide($run, $existing);

        if ($decision === null) {
            $run->rowOutcome(self::SOURCE_QUIZ, RowProblem::OUTCOME_UNCHANGED);

            // The existing id, so a create_only run still reaches the
            // questions this quiz gained and the lesson that points at it.
            // See saveCourse() for the whole of the reasoning.
            return $existing;
        }

        if ($run->isDryRun()) {
            $run->counted(ImportResult::TOTAL_QUIZZES);
            $run->counted(ImportResult::TOTAL_QUESTIONS, \count($item->questions));

            return 0;
        }

        try {
            $quizId = $decision === RowProblem::OUTCOME_UPDATED && $existing !== null
                ? $existing
                : $this->createQuiz->execute(
                    $run->principal,
                    $courseId,
                    $item->title,
                    passMark: $item->passMark,
                    maxAttempts: $item->maxAttempts,
                    timeLimitSeconds: $item->timeLimit,
                )->id;
        } catch (\Throwable $failure) {
            $run->problem(
                self::SOURCE_QUIZ,
                self::rowOf($item->sourceId),
                'post_title',
                RowProblem::CODE_MISSING_REQUIRED,
                ImportProblem::describe($failure),
                $item->title,
                RowProblem::OUTCOME_FAILED,
            );
            $run->skipped(sprintf(
                'Quiz "%s" was not imported: %s',
                ImportNote::excerpt($item->title),
                ImportProblem::describe($failure),
            ));

            return null;
        }

        if ($quizId === null) {
            return null;
        }

        $this->remember($run, self::SOURCE_QUIZ, $key, self::LOCAL_QUIZ, $quizId, $decision);
        $run->counted(ImportResult::TOTAL_QUIZZES);

        return $quizId;
    }

    /**
     * @return int|null the local lesson id, 0 in a dry run, null when it was
     *                  not written
     */
    private function saveLesson(
        WordPressImportRun $run,
        CourseDraft $draft,
        ItemDraft $item,
        int $moduleId,
        ?int $quizId,
    ): ?int {
        $key = $this->scopedKey($draft->sourceId, $item->sourceId);
        $existing = $this->map->lookup($this->key(), self::SOURCE_LESSON, $key);
        $decision = $this->decide($run, $existing);

        if ($decision === null) {
            $run->rowOutcome(self::SOURCE_LESSON, RowProblem::OUTCOME_UNCHANGED);

            // The existing id, so a create_only run still remembers this
            // lesson for the completions that name it. See saveCourse().
            return $existing;
        }

        $content = $item->isQuiz() ? ['quiz_id' => $quizId] : $item->content;
        $needsDuration = $item->lessonType === 'video' && ($item->durationSeconds ?? 0) < 1;

        if ($run->isDryRun()) {
            $run->counted(ImportResult::TOTAL_LESSONS);

            return 0;
        }

        try {
            if ($decision === RowProblem::OUTCOME_UPDATED && $existing !== null) {
                $this->updateLesson->execute($run->principal, $existing, [
                    'title' => $item->title,
                    'type' => $item->lessonType,
                    'content' => $content,
                    'duration_seconds' => $item->durationSeconds,
                ] + ($needsDuration ? ['state' => 'draft'] : []));
                $lessonId = $existing;
            } else {
                $lessonId = $this->addLesson->execute(
                    $run->principal,
                    $moduleId,
                    $item->title,
                    $item->lessonType,
                    $content,
                    durationSeconds: $item->durationSeconds,
                    state: $needsDuration ? 'draft' : 'published',
                )->id;
            }
        } catch (\Throwable $failure) {
            $run->problem(
                $item->isQuiz() ? self::SOURCE_QUIZ : self::SOURCE_LESSON,
                self::rowOf($item->sourceId),
                'post_content',
                RowProblem::CODE_MISSING_REQUIRED,
                ImportProblem::describe($failure),
                $item->title,
                RowProblem::OUTCOME_FAILED,
            );
            $run->skipped(sprintf(
                'Lesson "%s" was not imported: %s',
                ImportNote::excerpt($item->title),
                ImportProblem::describe($failure),
            ));

            return null;
        }

        $this->remember($run, self::SOURCE_LESSON, $key, self::LOCAL_LESSON, $lessonId, $decision);
        $run->counted(ImportResult::TOTAL_LESSONS);

        if ($needsDuration) {
            $run->warn(sprintf('Video "%s" was saved as a draft. Set its duration before publishing.', ImportNote::excerpt($item->title)));
        }

        return $lessonId;
    }

    private function saveQuestion(
        WordPressImportRun $run,
        CourseDraft $draft,
        ItemDraft $item,
        QuestionDraft $question,
        int $quizId,
    ): void {
        $key = $this->scopedKey($draft->sourceId, $item->sourceId . '/' . $question->sourceId);
        $existing = $this->map->lookup($this->key(), self::SOURCE_QUESTION, $key);
        $decision = $this->decide($run, $existing);

        if ($decision === null) {
            $run->rowOutcome(self::SOURCE_QUESTION, RowProblem::OUTCOME_UNCHANGED);

            return;
        }

        if ($question->note !== null) {
            $run->tally('changed:' . $question->note);
        }

        try {
            $questionId = $decision === RowProblem::OUTCOME_UPDATED && $existing !== null
                ? $this->updateQuestion->execute($run->principal, $existing, $question->definition, $question->points)->id
                : $this->addQuestion->execute(
                    $run->principal,
                    $quizId,
                    $question->type,
                    $question->definition,
                    $question->points,
                )->id;
        } catch (\Throwable $failure) {
            $run->problem(
                self::SOURCE_QUESTION,
                self::rowOf($question->sourceId),
                'answer_data',
                RowProblem::CODE_MISSING_REQUIRED,
                ImportProblem::describe($failure),
                $this->promptOf($question),
                RowProblem::OUTCOME_FAILED,
            );
            $run->skipped(sprintf(
                'Question %s of quiz "%s" was not imported: %s',
                $question->sourceId,
                ImportNote::excerpt($item->title),
                ImportProblem::describe($failure),
            ));

            return;
        }

        if ($questionId === null) {
            return;
        }

        $this->remember($run, self::SOURCE_QUESTION, $key, self::LOCAL_QUESTION, $questionId, $decision);
        $run->counted(ImportResult::TOTAL_QUESTIONS);
    }

    private function saveMemberships(WordPressImportRun $run, CourseDraft $draft, int $courseId): void
    {
        if ($draft->memberships === []) {
            return;
        }

        if (!$run->learners) {
            $run->warn(sprintf(
                'Course "%s" has %d %s learner(s), which were not imported. Learners are imported only when the'
                    . ' "%s" option asks for them, because granting access emails them a welcome for a course they'
                    . ' joined years ago. Re-run with it on when you are ready.',
                ImportNote::excerpt($draft->title),
                \count($draft->memberships),
                $this->productName(),
                self::OPTION_LEARNERS,
            ));
            $run->counted(ImportResult::TOTAL_SKIPPED, \count($draft->memberships));

            return;
        }

        foreach ($draft->memberships as $index => $membership) {
            try {
                $this->saveMembership($run, $draft, $membership, $courseId);
            } catch (NotAuthorizedException $failure) {
                $remaining = \count($draft->memberships) - $index;
                $run->warn(sprintf(
                    'The account running this import may not enrol learners, so %d learner(s) of course "%s" were'
                        . ' not imported: %s',
                    $remaining,
                    ImportNote::excerpt($draft->title),
                    ImportProblem::describe($failure),
                ));
                $run->counted(ImportResult::TOTAL_SKIPPED, $remaining);

                return;
            }
        }
    }

    /**
     * @throws NotAuthorizedException when the importing account may not enrol
     *                                anybody, which is the same answer for
     *                                every remaining learner
     */
    private function saveMembership(
        WordPressImportRun $run,
        CourseDraft $draft,
        MembershipDraft $membership,
        int $courseId,
    ): void {
        if ($membership->userId < 1) {
            $run->problem(
                self::SOURCE_ENROLMENT,
                self::rowOf($membership->sourceId),
                'user_id',
                RowProblem::CODE_REFERENTIAL_MISS,
                'The enrolment names no WordPress user.',
                $membership->sourceId,
            );

            return;
        }

        if ($run->isDryRun()) {
            $run->counted(ImportResult::TOTAL_ENROLMENTS);

            return;
        }

        $key = $this->scopedKey($draft->sourceId, $membership->sourceId);

        try {
            $entitlement = $this->grantEntitlement->execute(
                $run->principal,
                $membership->userId,
                $courseId,
                Entitlement::SOURCE_IMPORT,
                sourceRef: $this->entitlementRef($key),
                expiresAt: $membership->expiresAt,
            );
        } catch (NotAuthorizedException $refusal) {
            throw $refusal;
        } catch (\Throwable $failure) {
            $run->problem(
                self::SOURCE_ENROLMENT,
                self::rowOf($membership->sourceId),
                'user_id',
                RowProblem::CODE_MISSING_REQUIRED,
                ImportProblem::describe($failure),
                (string) $membership->userId,
                RowProblem::OUTCOME_FAILED,
            );
            $run->skipped(sprintf(
                'Learner %d of course "%s" was not imported: %s',
                $membership->userId,
                ImportNote::excerpt($draft->title),
                ImportProblem::describe($failure),
            ));

            return;
        }

        $this->remember($run, self::SOURCE_ENROLMENT, $key, self::LOCAL_ENTITLEMENT, $entitlement->id());
        $run->counted(ImportResult::TOTAL_ENROLMENTS);

        if (!$run->progressRows || $membership->completedItems === []) {
            $run->tally('history:lesson', \count($membership->completedItems));

            return;
        }

        $this->replay($run, $membership, $courseId, $entitlement->id());
    }

    /**
     * Writes what the product says the learner finished through the progress
     * use case's manager-authorised import entry point. Courses remain drafts
     * until the operator chooses to publish; their history belongs to the
     * enrolled learner and does not grant learner access to those drafts.
     *
     * What it cannot do is make the completion old. Every completed lesson is
     * dated now, and a course that completes issues a certificate dated now.
     * That is why the option is off by default and why the note says it once
     * per course rather than burying it.
     */
    private function replay(WordPressImportRun $run, MembershipDraft $membership, int $courseId, int $entitlementId): void
    {
        try {
            $enrolment = $this->enrolLearner->execute($membership->userId, $courseId, $entitlementId);
        } catch (\Throwable $failure) {
            $run->skipped(sprintf(
                'Learner %d has access but their history was not imported: %s',
                $membership->userId,
                ImportProblem::describe($failure),
            ));

            return;
        }

        foreach ($membership->completedItems as $sourceId) {
            $lessonId = $run->lessonFor($sourceId);

            if ($lessonId === null || $lessonId < 1) {
                $run->problem(
                    self::SOURCE_LESSON,
                    self::rowOf($sourceId),
                    null,
                    RowProblem::CODE_REFERENTIAL_MISS,
                    'The finished lesson was not one this run imported.',
                    $sourceId,
                );

                continue;
            }

            try {
                $this->recordLessonProgress->importCompletion($run->principal, $enrolment->id(), $lessonId);
                $run->counted('progress');
            } catch (\Throwable $failure) {
                $run->problem(
                    self::SOURCE_LESSON,
                    self::rowOf($sourceId),
                    null,
                    RowProblem::CODE_MISSING_REQUIRED,
                    ImportProblem::describe($failure),
                    $sourceId,
                    RowProblem::OUTCOME_FAILED,
                );
            }
        }
    }

    /**
     * What this run does about an object the map already knows, or null when
     * it leaves it alone.
     *
     * @return string|null one of the RowProblem::OUTCOME_* constants
     */
    protected function decide(WordPressImportRun $run, ?int $existing): ?string
    {
        if ($existing === null) {
            return $run->mayCreate() ? RowProblem::OUTCOME_CREATED : null;
        }

        if ($run->duplicates()) {
            return RowProblem::OUTCOME_CREATED;
        }

        return $run->mayUpdate() ? RowProblem::OUTCOME_UPDATED : null;
    }

    /**
     * The closing summary: one line per kind of thing left behind, and one
     * line per file that carried a row problem.
     */
    protected function summarise(WordPressImportRun $run): void
    {
        foreach ($run->tallies('question:') as $type => $count) {
            $run->warn(sprintf(
                '%d question(s) of type "%s" were not imported: the free build has no question type that carries'
                    . ' them, and converting them into a different type silently would change what the learner is'
                    . ' asked.',
                $count,
                ImportNote::excerpt($type, 40),
            ));
        }

        foreach ($run->tallies('lesson:') as $type => $count) {
            $run->warn(sprintf(
                '%d lesson(s) of type "%s" were not imported: the free build has no lesson type that carries them.',
                $count,
                ImportNote::excerpt($type, 40),
            ));
        }

        foreach ($run->tallies('changed:') as $note => $count) {
            $run->warn(sprintf('%d question(s) came across changed: %s.', $count, $note));
        }

        $quizzes = $run->tallies('content:');

        if (($quizzes['quiz'] ?? 0) > 0) {
            $run->note(sprintf(
                '%d quiz(zes) were not imported, because this run asked for the curriculum only.',
                $quizzes['quiz'],
            ));
        }

        $history = $run->tallies('history:');

        if (($history['lesson'] ?? 0) > 0) {
            $run->note(sprintf(
                '%d completed lesson(s) were left behind. Progress is imported only when the "%s" option asks for'
                    . ' it, because a replayed completion is dated today and issues today\'s certificate for work'
                    . ' done years ago.',
                $history['lesson'],
                self::OPTION_PROGRESS,
            ));
        }

        // The Canvas shaped per-file summary is the recorder's, written once
        // when the run ends. What belongs here is the one line that decides
        // whether the job finished cleanly or finished with problems: a run
        // that rejected a row must not report itself green, and one line is
        // what keeps the log linear when ten thousand rows were rejected.
        if ($run->rejected() > 0) {
            $run->failed(sprintf(
                '%d row(s) were rejected. They are listed against this job, one row each, with the column and the'
                    . ' reason; fix them at the source and run the same import again.',
                $run->rejected(),
            ));
        }
    }

    /**
     * Records what this run created, under a key scoped to the course it
     * belongs to.
     */
    protected function remember(
        WordPressImportRun $run,
        string $sourceType,
        string $sourceKey,
        string $localType,
        int $localId,
        string $outcome = RowProblem::OUTCOME_CREATED,
    ): void {
        if ($run->isDryRun() || $localId < 1) {
            return;
        }

        // The row went right, so it is counted the same way a row that went
        // wrong is: one report, into the file's summary and into the run's
        // per-outcome totals, so the two counters cannot disagree.
        $run->rowOutcome($sourceType, $outcome);

        try {
            $this->map->remember($this->key(), $sourceType, $sourceKey, $localType, $localId, $run->progress->jobId());
        } catch (\Throwable $failure) {
            $run->warn(sprintf(
                'The import map could not record %s "%s": %s. A re-run will create a second copy of it.',
                $sourceType,
                ImportNote::excerpt($sourceKey, 80),
                ImportProblem::describe($failure),
            ));
        }
    }

    /**
     * A source key that names the course the copy belongs to.
     *
     * All three products let one quiz be reused by several courses while the
     * free build's quiz belongs to exactly one, so each course gets its own
     * copy and each copy keeps its own identity across re-runs. The importer
     * key is already part of every map lookup, so nothing here needs to name
     * the product; and unlike Community Quiz, a WordPress site cannot hold two
     * installs of one plugin, so nothing needs to name the install either.
     */
    protected function scopedKey(string $courseSourceId, string $sourceId): string
    {
        return sprintf('course:%s/%s', $courseSourceId, $sourceId);
    }

    /**
     * The entitlement's source reference, which is what makes a re-run grant
     * nothing rather than grant twice.
     *
     * The grammar is the shipped importer's: the importer key, a colon, and
     * the map key. It stays inside `SourceRef::MAX_LENGTH` because the map key
     * is two numbers and a word.
     */
    protected function entitlementRef(string $key): string
    {
        return sprintf('%s:%s', $this->key(), $key);
    }

    /**
     * The title a `create_with_suffix` run gives a second copy.
     */
    protected function suffixed(string $title): string
    {
        return mb_substr($title, 0, 220) . ' (' . $this->productName() . ' import)';
    }

    private function promptOf(QuestionDraft $question): string
    {
        /** @var mixed $prompt */
        $prompt = $question->definition['prompt'] ?? '';

        return \is_string($prompt) ? $prompt : '';
    }

    /**
     * The row a problem names. A database source has no header row, so the
     * source object's own identifier is the row number an operator matches
     * against.
     *
     * One derivation, in the class that owns the row concept. This took the
     * first run of digits and the Joomla family's SourceRun took the last, so
     * "course:12/40" reported 12 on one side and 40 on the other, for the same
     * object.
     */
    protected static function rowOf(string $sourceId): int
    {
        return RowProblem::rowFromKey($sourceId);
    }
}
