<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Assessment;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Assessment\AddQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\AttemptManifest;
use Shondalai\VidyaLMS\Core\Application\Assessment\AttemptRegrader;
use Shondalai\VidyaLMS\Core\Application\Assessment\ListIntegrityEvents;
use Shondalai\VidyaLMS\Core\Application\Assessment\RecordIntegrityEvent;
use Shondalai\VidyaLMS\Core\Application\Assessment\RegradeAttempt;
use Shondalai\VidyaLMS\Core\Application\Assessment\RegradeQuiz;
use Shondalai\VidyaLMS\Core\Application\Assessment\QuestionTypes;
use Shondalai\VidyaLMS\Core\Application\Assessment\CreateQuiz;
use Shondalai\VidyaLMS\Core\Application\Assessment\GetAttemptForLearner;
use Shondalai\VidyaLMS\Core\Application\Assessment\LearnerAccessibilityProfile;
use Shondalai\VidyaLMS\Core\Application\Assessment\SignedMedia;
use Shondalai\VidyaLMS\Core\Application\Assessment\GradeAnswerManually;
use Shondalai\VidyaLMS\Core\Application\Assessment\RemoveQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\SaveAnswers;
use Shondalai\VidyaLMS\Core\Application\Assessment\StartAttempt;
use Shondalai\VidyaLMS\Core\Application\Assessment\SubmitAttempt;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuestion;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuiz;
use Shondalai\VidyaLMS\Core\Application\Curriculum\LessonAvailability;
use Shondalai\VidyaLMS\Core\Application\Platform\FeatureProviderLedger;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseAttemptQuestionRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseAttemptRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseQuestionDefinitionStore;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseQuestionRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseIntegrityEventRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseQuizRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment\DatabaseStimulusRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\DatabaseEnrolmentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseCourseRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseFeatureProviderRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseSettingsRepository;
use Shondalai\VidyaLMS\Core\Provider\Aggregation\HighestAggregationMethod;
use Shondalai\VidyaLMS\Core\Provider\Aggregation\LatestAggregationMethod;
use Shondalai\VidyaLMS\Core\Provider\AttemptPolicy\DeferredFeedbackAttemptPolicy;
use Shondalai\VidyaLMS\Core\Provider\AttemptPolicy\ManualAttemptPolicy;
use Shondalai\VidyaLMS\Core\Provider\Composition\PublishedQuestionsComposition;
use Shondalai\VidyaLMS\Core\Provider\Interaction\ArtifactPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\AssociatePrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\OrderPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\PointPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\ScalarPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\SelectPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\SignalPrimitive;
use Shondalai\VidyaLMS\Core\Provider\Interaction\TextPrimitive;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\FillBlankQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\MultipleChoiceQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\OrderingQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\ShortAnswerQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\SingleChoiceQuestionType;
use Shondalai\VidyaLMS\Core\Provider\QuestionType\TrueFalseQuestionType;
use Shondalai\VidyaLMS\Core\Provider\Scoring\FreeScoringPolicies;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodRegistry;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Composition\QuizCompositionRegistry;
use Shondalai\VidyaLMS\Core\Registry\NegativeMarking\NegativeMarkingRegistry;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveRegistry;
use Shondalai\VidyaLMS\Core\Registry\DefinitionUpgrader\DefinitionUpgraderRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

abstract class AssessmentIntegrationTestCase extends TestCase
{
    protected const TEACHER_ID = 10;
    protected const GRADER_ID = 20;
    protected const LEARNER_ID = 30;
    protected const OTHER_LEARNER_ID = 31;

    protected TestPorts $env;
    protected QuestionTypeRegistry $questionTypes;
    protected DatabaseQuizRepository $quizzes;
    protected DatabaseQuestionRepository $questions;
    protected DatabaseAttemptRepository $attempts;
    protected DatabaseAttemptQuestionRepository $attemptQuestions;
    protected DatabaseQuestionDefinitionStore $questionDefinitions;
    protected ScoringPolicyRegistry $scoringPolicies;

    /** The version chains this site can run. Empty unless a test fills it. */
    protected DefinitionUpgraderRegistry $definitionUpgraders;
    protected InteractionPrimitiveRegistry $primitives;
    protected AttemptPolicyRegistry $attemptPolicies;
    protected AggregationMethodRegistry $aggregationMethods;
    protected NegativeMarkingRegistry $negativeMarking;
    protected QuizCompositionRegistry $compositions;
    protected DatabaseStimulusRepository $stimuli;
    protected DatabaseIntegrityEventRepository $integrityEvents;
    protected FeatureProviderLedger $featureProviders;
    protected QuestionTypes $typeResolver;
    protected AttemptManifest $manifest;
    protected AccessGuard $guard;
    protected CourseAccess $access;
    protected DatabaseCourseRepository $courses;
    protected EventBus $events;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();

        $this->scoringPolicies = FreeScoringPolicies::registry();
        $this->definitionUpgraders = new DefinitionUpgraderRegistry();
        $this->primitives = new InteractionPrimitiveRegistry();
        $this->primitives->register(new SelectPrimitive());
        $this->primitives->register(new OrderPrimitive());
        $this->primitives->register(new AssociatePrimitive());
        $this->primitives->register(new TextPrimitive());
        $this->primitives->register(new ScalarPrimitive());
        $this->primitives->register(new PointPrimitive());
        $this->primitives->register(new ArtifactPrimitive());
        $this->primitives->register(new SignalPrimitive());

        $this->attemptPolicies = new AttemptPolicyRegistry();
        $this->attemptPolicies->register(new DeferredFeedbackAttemptPolicy($this->env->translator));
        $this->attemptPolicies->register(new ManualAttemptPolicy($this->env->translator));

        $this->aggregationMethods = new AggregationMethodRegistry();
        $this->aggregationMethods->register(new HighestAggregationMethod($this->env->translator));
        $this->aggregationMethods->register(new LatestAggregationMethod($this->env->translator));

        // The free build registers no negative marking rule at all, and the
        // seam is exercised here rather than only by a paid package.
        $this->negativeMarking = new NegativeMarkingRegistry();
        $this->compositions = new QuizCompositionRegistry();

        $this->questionTypes = new QuestionTypeRegistry();
        $this->questionTypes->register(new SingleChoiceQuestionType($this->env->translator, $this->scoringPolicies));
        $this->questionTypes->register(new MultipleChoiceQuestionType($this->env->translator, $this->scoringPolicies));
        $this->questionTypes->register(new TrueFalseQuestionType($this->env->translator, $this->scoringPolicies));
        $this->questionTypes->register(new ShortAnswerQuestionType($this->env->translator, $this->scoringPolicies));
        $this->questionTypes->register(new FillBlankQuestionType($this->env->translator, $this->scoringPolicies));
        $this->questionTypes->register(new OrderingQuestionType($this->env->translator, $this->scoringPolicies));

        $this->quizzes = new DatabaseQuizRepository($this->env->database, $this->env->clock);
        $this->questions = new DatabaseQuestionRepository(
            $this->env->database,
            $this->env->clock,
            $this->questionTypes,
            $this->scoringPolicies,
            $this->definitionUpgraders,
        );
        $this->attempts = new DatabaseAttemptRepository($this->env->database, $this->env->clock);
        $this->attemptQuestions = new DatabaseAttemptQuestionRepository($this->env->database, $this->env->clock);
        $this->questionDefinitions = new DatabaseQuestionDefinitionStore($this->env->database, $this->env->clock);
        $this->featureProviders = new FeatureProviderLedger(
            new DatabaseFeatureProviderRepository($this->env->database, $this->env->clock),
            new DatabaseSettingsRepository($this->env->database, $this->env->clock),
        );
        $this->typeResolver = new QuestionTypes(
            $this->questionTypes,
            $this->env->translator,
            $this->featureProviders,
        );
        $this->manifest = new AttemptManifest(
            $this->attemptQuestions,
            $this->questionDefinitions,
            $this->questions,
            $this->typeResolver,
            $this->primitives,
            $this->env->locale,
        );
        $this->compositions->register(
            new PublishedQuestionsComposition($this->questions, $this->env->translator),
        );
        $this->stimuli = new DatabaseStimulusRepository($this->env->database, $this->env->clock);
        $this->integrityEvents = new DatabaseIntegrityEventRepository($this->env->database, $this->env->clock);
        $this->guard = new AccessGuard($this->env->authorization);
        $this->courses = new DatabaseCourseRepository($this->env->database, $this->env->clock);
        $this->access = new CourseAccess(
            $this->guard,
            $this->courses,
            new DatabaseEnrolmentRepository($this->env->database, $this->env->clock),
        );
        $this->events = new EventBus($this->env->eventBridge, new NullLogger());

        $this->env->authorization->grant(self::TEACHER_ID, Capability::TEACH);
        $this->env->authorization->grant(self::GRADER_ID, Capability::GRADE);
    }

    protected function teacher(): Principal
    {
        return Principal::user(self::TEACHER_ID);
    }

    protected function grader(): Principal
    {
        return Principal::user(self::GRADER_ID);
    }

    protected function learner(): Principal
    {
        return Principal::user(self::LEARNER_ID);
    }

    protected function createQuizUseCase(): CreateQuiz
    {
        return new CreateQuiz(
            $this->quizzes,
            $this->env->database,
            $this->access,
            $this->attemptPolicies,
            $this->aggregationMethods,
            $this->negativeMarking,
        );
    }

    protected function updateQuizUseCase(): UpdateQuiz
    {
        return new UpdateQuiz(
            $this->quizzes,
            $this->env->database,
            $this->access,
            $this->attemptPolicies,
            $this->aggregationMethods,
            $this->negativeMarking,
            $this->questions,
        );
    }

    protected function addQuestionUseCase(): AddQuestion
    {
        return new AddQuestion($this->quizzes, $this->questions, $this->questionTypes, $this->access);
    }

    protected function updateQuestionUseCase(): UpdateQuestion
    {
        return new UpdateQuestion($this->questions, $this->quizzes, $this->typeResolver, $this->access);
    }

    protected function removeQuestionUseCase(): RemoveQuestion
    {
        return new RemoveQuestion($this->questions, $this->quizzes, $this->access);
    }

    protected function startAttemptUseCase(): StartAttempt
    {
        return new StartAttempt(
            $this->quizzes,
            $this->attempts,
            $this->env->database,
            $this->guard,
            $this->env->clock,
            new LessonAvailability($this->env->database),
            $this->attemptPolicies,
            $this->manifest,
            $this->env->random,
            $this->compositions,
            $this->negativeMarking,
            new LearnerAccessibilityProfile($this->env->database),
            $this->env->locale,
        );
    }

    protected function saveAnswersUseCase(): SaveAnswers
    {
        return new SaveAnswers(
            $this->quizzes,
            $this->attempts,
            $this->attemptQuestions,
            $this->manifest,
            $this->typeResolver,
            $this->guard,
            $this->env->clock,
            $this->env->database,
            $this->env->locale,
        );
    }

    protected function submitAttemptUseCase(): SubmitAttempt
    {
        return new SubmitAttempt(
            $this->quizzes,
            $this->attempts,
            $this->manifest,
            $this->typeResolver,
            $this->scoringPolicies,
            $this->guard,
            $this->events,
            $this->env->clock,
            $this->env->database,
            $this->env->locale,
        );
    }

    protected function gradeAnswerManuallyUseCase(): GradeAnswerManually
    {
        return new GradeAnswerManually(
            $this->quizzes,
            $this->questions,
            $this->attempts,
            $this->manifest,
            $this->typeResolver,
            $this->access,
            $this->events,
            $this->env->clock,
        );
    }

    protected function regrader(): AttemptRegrader
    {
        return new AttemptRegrader(
            $this->attempts,
            $this->manifest,
            $this->questions,
            $this->typeResolver,
            $this->scoringPolicies,
            $this->env->clock,
            $this->env->translator,
            $this->env->locale,
        );
    }

    protected function regradeQuizUseCase(): RegradeQuiz
    {
        return new RegradeQuiz($this->quizzes, $this->questions, $this->attempts, $this->regrader(), $this->access);
    }

    protected function regradeAttemptUseCase(): RegradeAttempt
    {
        return new RegradeAttempt($this->attempts, $this->quizzes, $this->regrader(), $this->access);
    }

    protected function recordIntegrityEventUseCase(): RecordIntegrityEvent
    {
        return new RecordIntegrityEvent($this->attempts, $this->integrityEvents, $this->guard, $this->env->clock);
    }

    protected function listIntegrityEventsUseCase(): ListIntegrityEvents
    {
        return new ListIntegrityEvents($this->attempts, $this->quizzes, $this->integrityEvents, $this->access);
    }

    protected function getAttemptForLearnerUseCase(): GetAttemptForLearner
    {
        return new GetAttemptForLearner(
            $this->quizzes,
            $this->attempts,
            $this->manifest,
            $this->guard,
            $this->env->database,
            $this->typeResolver,
            new SignedMedia($this->env->storage),
        );
    }

    protected function createCourse(string $slug = 'course-a'): int
    {
        $now = $this->now();

        $courseId = $this->env->database->insert('courses', [
            'title' => 'Course ' . $slug,
            'slug' => $slug,
            'state' => 'published',
            'created_by' => self::TEACHER_ID,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // The per-course rule grants a non-manager only the courses they are
        // an instructor of, so the fixture's staff are staff on its courses.
        $this->courses->assignInstructor($courseId, self::TEACHER_ID, 'lead');
        $this->courses->assignInstructor($courseId, self::GRADER_ID, 'grader');

        return $courseId;
    }

    /**
     * A lesson always sits in a module, and whether it is reachable depends on
     * that module's state as much as its own, so a fixture that skipped the
     * module row was testing a shape the product cannot produce.
     */
    protected function createModule(int $courseId, string $state = 'published'): int
    {
        $now = $this->now();

        return $this->env->database->insert('modules', [
            'course_id' => $courseId,
            'title' => 'Module',
            'position' => 1,
            'state' => $state,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function createLesson(int $courseId, string $type = 'quiz', string $slug = 'lesson-a'): int
    {
        $now = $this->now();

        return $this->env->database->insert('lessons', [
            'course_id' => $courseId,
            'module_id' => $this->createModule($courseId),
            'title' => 'Lesson ' . $slug,
            'slug' => $slug,
            'type' => $type,
            'position' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function createEnrolment(int $userId, int $courseId, string $status = 'active'): int
    {
        $now = $this->now();

        return $this->env->database->insert('enrolments', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'entitlement_id' => 1,
            'status' => $status,
            'enrolled_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function createQuiz(
        int $courseId,
        int $passMark = 0,
        ?int $maxAttempts = null,
        string $showResults = Quiz::SHOW_RESULTS_AFTER_SUBMIT,
        ?int $lessonId = null,
        ?int $timeLimitSeconds = null,
        ?QuizPolicy $policy = null,
    ): Quiz {
        return $this->quizzes->add(new Quiz(
            id: null,
            courseId: $courseId,
            lessonId: $lessonId,
            title: 'Checkpoint',
            description: null,
            passMark: $passMark,
            maxAttempts: $maxAttempts,
            timeLimitSeconds: $timeLimitSeconds,
            isFinal: false,
            showResults: $showResults,
            // These fixtures represent assessments already opened by their author.
            policy: $lessonId === null ? new QuizPolicy(...array_replace(
                get_object_vars($policy ?? new QuizPolicy()),
                ['opensAt' => $policy->opensAt ?? $this->env->clock->now()],
            )) : ($policy ?? new QuizPolicy()),
        ));
    }

    protected function now(): string
    {
        return $this->env->clock->now()->format('Y-m-d H:i:s');
    }

    protected static function qid(Question $question): int
    {
        \assert($question->id !== null);

        return $question->id;
    }
}
