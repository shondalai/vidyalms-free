<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

use Shondalai\VidyaLMS\Core\Application\Access\EnrolLearner;
use Shondalai\VidyaLMS\Core\Application\Access\GrantEntitlement;
use Shondalai\VidyaLMS\Core\Application\Assessment\AddQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\CreateQuiz;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuiz;
use Shondalai\VidyaLMS\Core\Application\Commerce\SourceRef;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddModule;
use Shondalai\VidyaLMS\Core\Application\Curriculum\CreateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\PublishCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateModule;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportMap;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportProblem;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Application\Progress\RecordLessonProgress;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;

/**
 * Writes a normalised foreign course into VidyaLMS through the same use cases
 * the authoring UI calls, and nothing else.
 *
 * ## Why three importers share one writer
 *
 * Shika, Guru and OSCampus are three foreign schemas and one target. Reading
 * is where they differ and writing is where they must not: if each of them
 * carried its own answer to what a re-run does, what an unsupported type
 * costs, or which use case an enrolment goes through, then three importers
 * written from documentation would ship three different products' worth of
 * behaviour behind one screen. The reader knows one product's tables; this
 * knows the use cases; neither knows the other's subject.
 *
 * It writes no table directly. An importer that writes a table directly is a
 * bug no matter how much faster it would be, and the reason is not tidiness:
 * a direct write skips the validation, the events and the ordering rules that
 * make imported data behave like hand-built data.
 *
 * ## The order, and why it is fixed here
 *
 * Course, then per module: the module, then per lesson its quiz (a lesson
 * pointing at a quiz needs the quiz to have an id), then the lesson, then the
 * quiz again to bind it to the lesson it now has. Then the questions. Then
 * learners, then their completions, then publication. Enforcing it once here
 * rather than per mapper is the same rule the rostering design states for its
 * own load order, and for the same reason: a row whose parent failed is
 * skipped with a referential_miss naming the parent, never retried and never
 * written somewhere else.
 *
 * ## Identity and the re-run
 *
 * Everything created is recorded in the import map under the importer's own
 * key and the source object's natural key, before the next object is read, so
 * a run that died halfway resumes by skipping what it already wrote. The
 * upsert policy decides what happens when the map already has an answer, and
 * it is a declared option rather than an inference, because an importer that
 * guesses will eventually guess wrong on a customer's live site.
 */
final class CurriculumWriter
{
    /** What these importers call the things, for the import map's source_type. */
    public const SOURCE_COURSE = 'course';
    public const SOURCE_MODULE = 'module';
    public const SOURCE_LESSON = 'lesson';
    public const SOURCE_QUIZ = 'quiz';
    public const SOURCE_QUESTION = 'question';
    public const SOURCE_ENROLMENT = 'enrolment';

    /** What the core calls them. */
    public const LOCAL_COURSE = 'course';
    public const LOCAL_MODULE = 'module';
    public const LOCAL_LESSON = 'lesson';
    public const LOCAL_QUIZ = 'quiz';
    public const LOCAL_QUESTION = 'question';
    public const LOCAL_ENTITLEMENT = 'entitlement';

    /**
     * The source_type words these importers mint, for DeclaresSourceTypes.
     * None of them is one of ImportSourceKey's reserved organisation words,
     * so none of them takes the tenant prefix: a Guru course belongs to the
     * site, not to an organisation, and saying otherwise would put a tenant
     * in a key that has no tenant to put there.
     *
     * @var list<string>
     */
    public const SOURCE_TYPES = [
        self::SOURCE_COURSE,
        self::SOURCE_MODULE,
        self::SOURCE_LESSON,
        self::SOURCE_QUIZ,
        self::SOURCE_QUESTION,
        self::SOURCE_ENROLMENT,
    ];

    /** What create_with_suffix appends to a title, before the run's own id. */
    public const SUFFIX = ' (import ';

    /** How much of a source type name a summary line quotes. */
    private const TYPE_EXCERPT = 40;

    public function __construct(
        private readonly ImportMap $map,
        private readonly CreateCourse $createCourse,
        private readonly UpdateCourse $updateCourse,
        private readonly PublishCourse $publishCourse,
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
     * @param string $importer the importer key, which is also the import map's own
     */
    public function write(string $importer, SourceTables $tables, SourceRun $run, CourseDraft $draft): void
    {
        $courseId = $this->course($importer, $tables, $run, $draft);

        if ($courseId === null) {
            return;
        }

        $run->rememberCourse($draft->key, $courseId);

        foreach ($draft->modules as $module) {
            $moduleId = $this->module($importer, $tables, $run, $module, $courseId);

            if ($moduleId === null) {
                $run->skippedQuietly('lesson-of-skipped-module', $tables->lessons, \count($module->lessons));
                continue;
            }

            foreach ($module->lessons as $lesson) {
                $this->lesson($importer, $tables, $run, $lesson, $courseId, $moduleId);
            }
        }

        $this->learners($importer, $tables, $run, $draft, $courseId);
        $this->publish($run, $draft, $courseId);
    }

    /**
     * The closing summary: everything counted per kind rather than per row, so
     * a course with two hundred hotspot questions writes one line instead of
     * two hundred.
     *
     * The rows themselves are already in the row report, one each, which is
     * where an operator goes to fix them. This is the other half, the shape of
     * what happened, which is what tells them whether the migration is worth
     * reviewing or worth re-running.
     */
    public function summarise(SourceRun $run): void
    {
        foreach ($run->tallies('question:') as $type => $count) {
            $run->warn(sprintf(
                '%d "%s" question(s) were not imported: the free build has no equivalent question type.'
                    . ' Rebuild them by hand, or use a Pro question type that carries them.',
                $count,
                ImportNote::excerpt($type, self::TYPE_EXCERPT),
            ));
        }

        foreach ($run->tallies('lesson:') as $type => $count) {
            $run->warn(sprintf(
                '%d "%s" lesson(s) were not imported: the free build has no equivalent lesson type.',
                $count,
                ImportNote::excerpt($type, self::TYPE_EXCERPT),
            ));
        }

        $this->summariseOne(
            $run,
            'lesson-of-skipped-module',
            '%d lesson(s) went with a module that could not be imported.',
        );
        $this->summariseOne(
            $run,
            'not-created',
            '%d course(s) were not created, because this run was asked to update what it had already'
                . ' imported and to create nothing new.',
        );
        $this->summariseOne(
            $run,
            'quiz-lesson-excluded',
            '%d quiz lesson(s) were not imported, because this run was asked for the curriculum without its'
                . ' quizzes. Re-run with the content option set to everything to bring them across.',
        );
        $this->summariseOne(
            $run,
            'repeat-purchase',
            '%d repeat purchase(s) name somebody who already has access, and were not imported a second time.',
        );
        $this->summariseOne(
            $run,
            'published-in-source',
            '%d course(s) were published in the source and are drafts here. Review what came across, then'
                . ' publish them, or re-run with the "publish" option on.',
        );

        if ($run->problemCount() > 0) {
            // The one error level note of an ordinary run, and it is what ends
            // the job as completed_with_problems rather than completed.
            // Finishing and finishing cleanly are different terminal states,
            // and an operator who sees a green job and finds out three weeks
            // later that four hundred questions never landed was told the
            // wrong one.
            $run->error(sprintf(
                '%d row(s) of the source did not come across as the source had them. Each one is in this'
                    . ' job\'s row report with its table, its id, the column at fault and the reason.',
                $run->problemCount(),
            ));
        }
    }

    /**
     * @return int|null the local course id, 0 in a dry run, null when nothing
     *                  was or will be written for this course
     */
    private function course(string $importer, SourceTables $tables, SourceRun $run, CourseDraft $draft): ?int
    {
        $label = ImportNote::excerpt($draft->title);
        $existing = $this->existing($importer, self::SOURCE_COURSE, $draft->key, $run);

        if ($run->isDryRun()) {
            return $this->previewed($run, $tables->courses, ImportResult::TOTAL_COURSES, $existing, sprintf(
                'Course "%s" would be %s.',
                $label,
                $this->verb($run, $existing),
            ));
        }

        if ($existing !== null && !$run->updatesExisting()) {
            $run->rowOutcome($tables->courses, RowProblem::OUTCOME_UNCHANGED);

            return $existing;
        }

        if ($existing === null && !$run->createsNew()) {
            $run->skippedQuietly('not-created', $tables->courses);

            return null;
        }

        try {
            if ($existing !== null) {
                $this->updateCourse->execute($run->principal, $existing, [
                    'title' => $draft->title,
                    'summary' => $draft->summary,
                    'description' => $draft->description,
                    'level' => $draft->level,
                    'visibility' => $draft->visibility,
                ]);
                $courseId = $existing;
            } else {
                $courseId = $this->createCourse->execute(
                    $run->principal,
                    $this->titleFor($run, $draft->title),
                    summary: $draft->summary,
                    description: $draft->description,
                    level: $draft->level,
                    visibility: $draft->visibility,
                )->id;
            }
        } catch (\Throwable $failure) {
            $run->problem(
                RowProblem::CODE_MISSING_REQUIRED,
                $tables->courses,
                $draft->key,
                null,
                $draft->title,
                sprintf('the course was not imported: %s', ImportProblem::describe($failure)),
            );

            return null;
        }

        $this->remember($importer, $run, self::SOURCE_COURSE, $draft->key, self::LOCAL_COURSE, $courseId);
        $run->counted(ImportResult::TOTAL_COURSES);
        $run->rowOutcome($tables->courses, $existing === null ? RowProblem::OUTCOME_CREATED : RowProblem::OUTCOME_UPDATED);
        $run->note(sprintf('Course "%s" %s.', $label, $existing === null ? 'created' : 'updated'));

        return $courseId;
    }

    private function module(string $importer, SourceTables $tables, SourceRun $run, ModuleDraft $draft, int $courseId): ?int
    {
        $existing = $this->existing($importer, self::SOURCE_MODULE, $draft->key, $run);

        if ($run->isDryRun()) {
            return $this->previewed($run, $tables->modules, ImportResult::TOTAL_MODULES, $existing, null);
        }

        if ($existing !== null && !$run->updatesExisting()) {
            $run->rowOutcome($tables->modules, RowProblem::OUTCOME_UNCHANGED);

            return $existing;
        }

        if ($existing === null && !$run->createsNew()) {
            $run->rowOutcome($tables->modules, RowProblem::OUTCOME_SKIPPED);

            return null;
        }

        try {
            if ($existing !== null) {
                $moduleId = $this->updateModule->execute(
                    $run->principal,
                    $existing,
                    ['title' => $draft->title, 'summary' => $draft->summary],
                )->id;
            } else {
                $moduleId = $this->addModule->execute($run->principal, $courseId, $draft->title, $draft->summary)->id;
            }
        } catch (\Throwable $failure) {
            $run->problem(
                RowProblem::CODE_MISSING_REQUIRED,
                $tables->modules,
                $draft->key,
                null,
                $draft->title,
                sprintf('the module was not imported: %s', ImportProblem::describe($failure)),
            );

            return null;
        }

        $this->remember($importer, $run, self::SOURCE_MODULE, $draft->key, self::LOCAL_MODULE, $moduleId);
        $run->counted(ImportResult::TOTAL_MODULES);
        $run->rowOutcome($tables->modules, $existing === null ? RowProblem::OUTCOME_CREATED : RowProblem::OUTCOME_UPDATED);

        return $moduleId;
    }

    private function lesson(
        string $importer,
        SourceTables $tables,
        SourceRun $run,
        LessonDraft $draft,
        int $courseId,
        int $moduleId,
    ): void {
        if ($draft->isRefused()) {
            // Named by the source's own word for the type, because the count
            // per type is what tells a site owner how much of their course
            // needs rebuilding by hand.
            $run->problem(
                RowProblem::CODE_UNMAPPED_ROLE,
                $tables->lessons,
                $draft->key,
                'type',
                $draft->sourceType,
                (string) $draft->refusal,
                RowProblem::OUTCOME_SKIPPED,
            );
            $run->tally('lesson:' . $draft->sourceType);

            return;
        }

        if ($draft->quiz !== null && !$run->quizzes) {
            // The run was asked for the curriculum only. The lesson goes with
            // its quiz rather than landing as an empty shell pointing at
            // nothing, and the summary says how many.
            $run->skippedQuietly('quiz-lesson-excluded', $tables->lessons);

            return;
        }

        $quizId = null;

        if ($draft->quiz !== null) {
            $quizId = $this->quiz($importer, $tables, $run, $draft->quiz, $courseId);

            if ($quizId === null) {
                $run->problem(
                    RowProblem::CODE_REFERENTIAL_MISS,
                    $tables->lessons,
                    $draft->key,
                    null,
                    $draft->title,
                    'its quiz was not imported, so the lesson that would have pointed at it was not either',
                    RowProblem::OUTCOME_SKIPPED,
                );

                return;
            }
        }

        $content = $draft->quiz === null ? $draft->content : ['quiz_id' => $quizId];
        $existing = $this->existing($importer, self::SOURCE_LESSON, $draft->key, $run);

        if ($run->isDryRun()) {
            $this->previewed($run, $tables->lessons, ImportResult::TOTAL_LESSONS, $existing, null);

            return;
        }

        if ($existing !== null && !$run->updatesExisting()) {
            $run->rowOutcome($tables->lessons, RowProblem::OUTCOME_UNCHANGED);

            return;
        }

        if ($existing === null && !$run->createsNew()) {
            $run->rowOutcome($tables->lessons, RowProblem::OUTCOME_SKIPPED);

            return;
        }

        try {
            if ($existing !== null) {
                $lessonId = $this->updateLesson->execute($run->principal, $existing, [
                    'title' => $draft->title,
                    'type' => $draft->type,
                    'content' => $content,
                    'duration_seconds' => $draft->durationSeconds,
                    'free_preview' => $draft->freePreview,
                ] + ($draft->type === 'video' && ($draft->durationSeconds ?? 0) < 1 ? ['state' => 'draft'] : []))->id;
            } else {
                $lessonId = $this->addLesson->execute(
                    $run->principal,
                    $moduleId,
                    $draft->title,
                    $draft->type,
                    $content,
                    durationSeconds: $draft->durationSeconds,
                    freePreview: $draft->freePreview,
                    state: $draft->type === 'video' && ($draft->durationSeconds ?? 0) < 1 ? 'draft' : 'published',
                )->id;
            }
        } catch (\Throwable $failure) {
            $run->problem(
                RowProblem::CODE_MISSING_REQUIRED,
                $tables->lessons,
                $draft->key,
                null,
                $draft->title,
                sprintf('the lesson was not imported: %s', ImportProblem::describe($failure)),
            );

            return;
        }

        $this->remember($importer, $run, self::SOURCE_LESSON, $draft->key, self::LOCAL_LESSON, $lessonId);
        $run->counted(ImportResult::TOTAL_LESSONS);
        $run->rowOutcome($tables->lessons, $existing === null ? RowProblem::OUTCOME_CREATED : RowProblem::OUTCOME_UPDATED);

        if ($draft->note !== null) {
            $run->warn(sprintf('Lesson "%s": %s.', ImportNote::excerpt($draft->title), $draft->note));
        }

        if ($quizId !== null && $quizId > 0) {
            $this->bindQuiz($run, $draft->quiz, $quizId, $lessonId);
        }
    }

    /**
     * @return int|null the local quiz id, 0 in a dry run, null when it was not written
     */
    private function quiz(string $importer, SourceTables $tables, SourceRun $run, QuizDraft $draft, int $courseId): ?int
    {
        $existing = $this->existing($importer, self::SOURCE_QUIZ, $draft->key, $run);

        if ($run->isDryRun()) {
            $this->previewed($run, $tables->quizzes, ImportResult::TOTAL_QUIZZES, $existing, null);
            $this->questions($importer, $tables, $run, $draft, 0);

            return 0;
        }

        if ($existing !== null && !$run->updatesExisting()) {
            $run->rowOutcome($tables->quizzes, RowProblem::OUTCOME_UNCHANGED);

            return $existing;
        }

        if ($existing === null && !$run->createsNew()) {
            $run->rowOutcome($tables->quizzes, RowProblem::OUTCOME_SKIPPED);

            return null;
        }

        try {
            $quizId = $existing ?? $this->createQuiz->execute(
                $run->principal,
                $courseId,
                $this->titleFor($run, $draft->title),
                description: $draft->description,
                passMark: $draft->passMark,
                maxAttempts: $draft->maxAttempts,
                timeLimitSeconds: $draft->timeLimitSeconds,
                isFinal: $draft->isFinal,
            )->id;
        } catch (\Throwable $failure) {
            $run->problem(
                RowProblem::CODE_MISSING_REQUIRED,
                $tables->quizzes,
                $draft->key,
                null,
                $draft->title,
                sprintf('the quiz was not imported: %s', ImportProblem::describe($failure)),
            );

            return null;
        }

        if ($quizId === null) {
            return null;
        }

        $this->remember($importer, $run, self::SOURCE_QUIZ, $draft->key, self::LOCAL_QUIZ, $quizId);
        $run->counted(ImportResult::TOTAL_QUIZZES);
        $run->rowOutcome($tables->quizzes, $existing === null ? RowProblem::OUTCOME_CREATED : RowProblem::OUTCOME_UPDATED);
        $this->questions($importer, $tables, $run, $draft, $quizId);

        return $quizId;
    }

    /**
     * The quiz's own settings and the lesson it belongs to, written once the
     * lesson exists. A quiz has to be created before its lesson so the lesson
     * can point at it, and the binding is the other half of that order.
     */
    private function bindQuiz(SourceRun $run, ?QuizDraft $draft, int $quizId, int $lessonId): void
    {
        if ($draft === null) {
            return;
        }

        try {
            $this->updateQuiz->execute(
                $run->principal,
                $quizId,
                $draft->title,
                lessonId: $lessonId,
                description: $draft->description,
                passMark: $draft->passMark,
                maxAttempts: $draft->maxAttempts,
                timeLimitSeconds: $draft->timeLimitSeconds,
                isFinal: $draft->isFinal,
            );
        } catch (\Throwable $failure) {
            // Not a lost row: the quiz is imported and reachable through the
            // course, it is only its lesson binding or a setting that did not
            // take. Say which, and leave the quiz alone.
            $run->warn(sprintf(
                'Quiz "%s" kept its previous settings: %s',
                ImportNote::excerpt($draft->title),
                ImportProblem::describe($failure),
            ));
        }
    }

    private function questions(string $importer, SourceTables $tables, SourceRun $run, QuizDraft $draft, int $quizId): void
    {
        foreach ($draft->questions as $question) {
            if ($question->isRefused()) {
                $run->problem(
                    RowProblem::CODE_UNMAPPED_ROLE,
                    $tables->questions,
                    $question->key,
                    'type',
                    $question->sourceType,
                    (string) $question->refusal,
                    RowProblem::OUTCOME_SKIPPED,
                );
                $run->tally('question:' . $question->sourceType);
                continue;
            }

            if ($run->isDryRun()) {
                $run->counted(ImportResult::TOTAL_QUESTIONS);
                $run->rowOutcome($tables->questions, RowProblem::OUTCOME_CREATED);
                continue;
            }

            $existing = $this->existing($importer, self::SOURCE_QUESTION, $question->key, $run);

            if ($existing !== null && !$run->updatesExisting()) {
                $run->rowOutcome($tables->questions, RowProblem::OUTCOME_UNCHANGED);
                continue;
            }

            if ($existing === null && !$run->createsNew()) {
                $run->rowOutcome($tables->questions, RowProblem::OUTCOME_SKIPPED);
                continue;
            }

            try {
                $questionId = $existing !== null
                    ? $this->updateQuestion->execute(
                        $run->principal,
                        $existing,
                        $question->definition,
                        $question->points,
                    )->id
                    : $this->addQuestion->execute(
                        $run->principal,
                        $quizId,
                        $question->type,
                        $question->definition,
                        $question->points,
                    )->id;
            } catch (\Throwable $failure) {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    $tables->questions,
                    $question->key,
                    null,
                    $question->sourceType,
                    sprintf('the question was not imported: %s', ImportProblem::describe($failure)),
                );
                continue;
            }

            if ($questionId === null) {
                continue;
            }

            $this->remember($importer, $run, self::SOURCE_QUESTION, $question->key, self::LOCAL_QUESTION, $questionId);
            $run->counted(ImportResult::TOTAL_QUESTIONS);
            $run->rowOutcome($tables->questions, $existing === null ? RowProblem::OUTCOME_CREATED : RowProblem::OUTCOME_UPDATED);

            if ($question->note !== null) {
                // A row that was written and changed is still a written row,
                // so the outcome above stands and this is the warning beside
                // it rather than a second count.
                $run->warn(sprintf('Question %s came across changed: %s.', $question->key, $question->note));
            }
        }
    }

    private function learners(string $importer, SourceTables $tables, SourceRun $run, CourseDraft $draft, int $courseId): void
    {
        if ($draft->learners === []) {
            return;
        }

        if (!$run->learners) {
            $run->warn(sprintf(
                'Course "%s" has %d learner(s) in the source, which were not imported. Learners are imported'
                    . ' only when the option asks for them, because granting access emails a person about a'
                    . ' course they joined years ago. Re-run with it on when you are ready.',
                ImportNote::excerpt($draft->title),
                \count($draft->learners),
            ));
            $run->rowOutcome($tables->learners, RowProblem::OUTCOME_SKIPPED, \count($draft->learners));

            return;
        }

        foreach ($draft->learners as $index => $learner) {
            if ($learner->userId < 1) {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    $tables->learners,
                    $learner->key,
                    'user_id',
                    (string) $learner->userId,
                    'the row names no learner account',
                );
                continue;
            }

            if ($run->isDryRun()) {
                $run->counted(ImportResult::TOTAL_ENROLMENTS);
                $run->rowOutcome($tables->learners, RowProblem::OUTCOME_CREATED);
                continue;
            }

            try {
                $entitlement = $this->grantEntitlement->execute(
                    $run->principal,
                    $learner->userId,
                    $courseId,
                    Entitlement::SOURCE_IMPORT,
                    sourceRef: SourceRef::compose($importer, 'learner', $learner->key, $courseId),
                    expiresAt: $learner->expiresAt,
                );
            } catch (NotAuthorizedException $failure) {
                // One refusal here is every refusal: the account running the
                // import either may enrol or may not, and reporting the same
                // sentence once per learner would bury the run's real news.
                $remaining = \count($draft->learners) - $index;
                $run->warn(sprintf(
                    'The account running this import may not enrol learners, so %d learner(s) of course "%s"'
                        . ' were not imported: %s',
                    $remaining,
                    ImportNote::excerpt($draft->title),
                    ImportProblem::describe($failure),
                ));
                $run->rowOutcome($tables->learners, RowProblem::OUTCOME_SKIPPED, $remaining);

                return;
            } catch (\Throwable $failure) {
                $run->problem(
                    RowProblem::CODE_MISSING_REQUIRED,
                    $tables->learners,
                    $learner->key,
                    null,
                    (string) $learner->userId,
                    sprintf('the learner was not imported: %s', ImportProblem::describe($failure)),
                );
                continue;
            }

            $this->remember(
                $importer,
                $run,
                self::SOURCE_ENROLMENT,
                $learner->key,
                self::LOCAL_ENTITLEMENT,
                $entitlement->id(),
            );
            $run->counted(ImportResult::TOTAL_ENROLMENTS);
            $run->rowOutcome($tables->learners, RowProblem::OUTCOME_CREATED);
            $this->completions($importer, $tables, $run, $learner, $courseId, $entitlement->id());
        }
    }

    /**
     * The lessons this person had finished, recorded through the progress
     * use case's manager-authorised import entry point. This can restore
     * history into a draft without exposing that course to the learner.
     *
     * Only lesson types that complete on view can be completed this way. A
     * quiz lesson needs an attempt and an assignment needs a submission, and
     * neither is something an import may invent, so those lessons are left for
     * the learner to do.
     */
    private function completions(
        string $importer,
        SourceTables $tables,
        SourceRun $run,
        LearnerDraft $learner,
        int $courseId,
        int $entitlementId,
    ): void {
        if ($learner->completedLessonKeys === [] || !$run->completions) {
            return;
        }

        try {
            $enrolment = $this->enrolLearner->execute($learner->userId, $courseId, $entitlementId);
        } catch (\Throwable $failure) {
            $run->warn(sprintf(
                'The completed lessons of learner %d were not imported: %s',
                $learner->userId,
                ImportProblem::describe($failure),
            ));

            return;
        }

        foreach ($learner->completedLessonKeys as $lessonKey) {
            $lessonId = $this->map->lookup($importer, self::SOURCE_LESSON, $lessonKey);

            if ($lessonId === null) {
                $run->problem(
                    RowProblem::CODE_REFERENTIAL_MISS,
                    $tables->learners,
                    $lessonKey,
                    'lesson',
                    $lessonKey,
                    'the lesson this completion names was not imported, so the completion was not either',
                    RowProblem::OUTCOME_SKIPPED,
                );
                continue;
            }

            try {
                $this->recordLessonProgress->importCompletion(
                    $run->principal,
                    $enrolment->id(),
                    $lessonId,
                );
            } catch (\Throwable $failure) {
                $run->warn(sprintf(
                    'Lesson %s was not marked complete for learner %d: %s',
                    $lessonKey,
                    $learner->userId,
                    ImportProblem::describe($failure),
                ));
                continue;
            }

            $run->counted('completions');
            $run->rowOutcome($tables->learners, RowProblem::OUTCOME_UPDATED);
        }
    }

    private function publish(SourceRun $run, CourseDraft $draft, int $courseId): void
    {
        if ($run->isDryRun() || $courseId < 1 || !$draft->publishedInSource) {
            return;
        }

        if (!$run->publish) {
            $run->tally('published-in-source');

            return;
        }

        try {
            $this->publishCourse->execute($run->principal, $courseId);
            $run->note(sprintf('Course "%s" published, as it was in the source.', ImportNote::excerpt($draft->title)));
        } catch (\Throwable $failure) {
            $run->warn(sprintf(
                'Course "%s" could not be published: %s',
                ImportNote::excerpt($draft->title),
                ImportProblem::describe($failure),
            ));
        }
    }

    private function summariseOne(SourceRun $run, string $tally, string $message): void
    {
        $count = $run->tallied($tally);

        if ($count > 0) {
            $run->warn(sprintf($message, $count));
        }
    }

    /**
     * What a previous run made of this source object, or null when it has not
     * seen it. create_with_suffix never looks, which is what lets it make the
     * second copy it exists to make.
     */
    private function existing(string $importer, string $sourceType, string $sourceKey, SourceRun $run): ?int
    {
        return $run->readsTheMap() ? $this->map->lookup($importer, $sourceType, $sourceKey) : null;
    }

    private function remember(
        string $importer,
        SourceRun $run,
        string $sourceType,
        string $sourceKey,
        string $localType,
        int $localId,
    ): void {
        if ($run->isDryRun() || $localId < 1 || !$run->readsTheMap()) {
            return;
        }

        $this->map->remember($importer, $sourceType, $sourceKey, $localType, $localId, $run->jobId());
    }

    /**
     * A title as this run writes it. Only create_with_suffix changes it, and
     * it names the job that made the copy, so two side by side copies can be
     * told apart by the run that produced them.
     */
    private function titleFor(SourceRun $run, string $title): string
    {
        if ($run->readsTheMap()) {
            return $title;
        }

        $jobId = $run->jobId();

        return $title . self::SUFFIX . ($jobId > 0 ? (string) $jobId : 'copy') . ')';
    }

    /**
     * The dry run's counting, which writes nothing and touches no map row.
     */
    private function previewed(SourceRun $run, string $table, string $what, ?int $existing, ?string $note): int
    {
        $run->counted($what);
        $run->rowOutcome($table, $existing === null ? RowProblem::OUTCOME_CREATED : RowProblem::OUTCOME_UPDATED);

        if ($note !== null) {
            $run->note($note);
        }

        return 0;
    }

    private function verb(SourceRun $run, ?int $existing): string
    {
        if ($existing !== null) {
            return $run->updatesExisting() ? 'updated' : 'left as it is';
        }

        return $run->createsNew() ? 'created' : 'skipped';
    }
}
