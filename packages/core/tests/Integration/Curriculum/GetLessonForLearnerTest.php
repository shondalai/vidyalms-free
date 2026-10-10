<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetLessonForLearner;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\DatabaseEnrolmentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseCourseRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseLessonRepository;
use Shondalai\VidyaLMS\Core\Provider\Aggregation\HighestAggregationMethod;
use Shondalai\VidyaLMS\Core\Provider\Aggregation\LatestAggregationMethod;
use Shondalai\VidyaLMS\Core\Provider\LessonType\AssignmentLessonType;
use Shondalai\VidyaLMS\Core\Provider\LessonType\QuizLessonType;
use Shondalai\VidyaLMS\Core\Provider\LessonType\ReadingLessonType;
use Shondalai\VidyaLMS\Core\Provider\LessonType\ResourceLessonType;
use Shondalai\VidyaLMS\Core\Provider\LessonType\VideoLessonType;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodRegistry;
use Shondalai\VidyaLMS\Core\Registry\LessonType\DefaultContentVersion;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The player view of one lesson: every access branch (enrolled, completed,
 * suspended, free preview, guest, draft non-leak, authoring), the unknown
 * provider placeholder, the quiz attempt summary, and the route through the
 * ApiKernel.
 */
final class GetLessonForLearnerTest extends TestCase
{
    private const LEARNER_ID = 31;
    private const OUTSIDER_ID = 32;
    private const TEACHER_ID = 33;

    private TestPorts $env;
    private DatabaseCourseRepository $courses;
    private GetLessonForLearner $getLessonForLearner;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();

        $lessonTypes = new LessonTypeRegistry();
        $lessonTypes->register(new ReadingLessonType($this->env->translator));
        $lessonTypes->register(new VideoLessonType($this->env->translator));
        $lessonTypes->register(new ResourceLessonType($this->env->translator));
        $lessonTypes->register(new QuizLessonType($this->env->translator));
        $lessonTypes->register(new AssignmentLessonType($this->env->translator));
        // A third-party type written before PresentsFreePreview existed: it
        // answers only "with access" and "without".
        $lessonTypes->register(new class () implements LessonTypeInterface {
            use DefaultContentVersion;

            public function key(): string
            {
                return 'third_party';
            }

            public function label(): string
            {
                return 'Third party';
            }

            public function completionMode(): string
            {
                return self::COMPLETION_ON_VIEW;
            }

            public function validateContent(array $content): void
            {
            }

            public function presentForLearner(array $content, bool $hasAccess): array
            {
                return ['secret' => $hasAccess ? ($content['secret'] ?? null) : null];
            }
        });

        $this->courses = new DatabaseCourseRepository($this->env->database, $this->env->clock);

        // The two aggregation methods the free build registers, so this
        // reads the reportable grade through the registry the kernel wires
        // rather than through the "no registry at all" fallback, which is
        // what let a substituted rule go unnoticed here.
        $aggregations = new AggregationMethodRegistry();
        $aggregations->register(new HighestAggregationMethod($this->env->translator));
        $aggregations->register(new LatestAggregationMethod($this->env->translator));

        $this->getLessonForLearner = new GetLessonForLearner(
            new DatabaseLessonRepository($this->env->database, $this->env->clock, $lessonTypes),
            $this->courses,
            $lessonTypes,
            new CourseAccess(
                new AccessGuard($this->env->authorization),
                $this->courses,
                new DatabaseEnrolmentRepository($this->env->database, $this->env->clock),
            ),
            $this->env->database,
            $aggregations,
        );
    }

    public function testEnrolledLearnerReceivesLessonContentAndTheirProgress(): void
    {
        $courseId = $this->seedCourse();
        $moduleId = $this->seedModule($courseId);
        $lessonId = $this->seedLesson($courseId, $moduleId, 'reading', content: ['body' => '<p>Steep well.</p>']);
        $enrolmentId = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedLessonProgress($enrolmentId, $lessonId, self::LEARNER_ID, 'in_progress', 40.5, 120, 35);

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertSame($lessonId, $view['id']);
        self::assertSame($courseId, $view['course_id']);
        self::assertSame($moduleId, $view['module_id']);
        self::assertSame('reading', $view['type']);
        self::assertSame(['body' => '<p>Steep well.</p>'], $view['content']);
        self::assertTrue($view['content_available']);
        self::assertNull($view['quiz']);
        self::assertSame([
            'status' => 'in_progress',
            'progress_percent' => 40.5,
            'seconds_spent' => 120,
            'position_seconds' => 35,
            'completed_at' => null,
        ], $view['progress']);
    }

    public function testCompletedEnrolmentStillOpensTheLesson(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'reading', content: ['body' => '<p>Done.</p>']);
        $this->seedEnrolment(self::LEARNER_ID, $courseId, 'completed');

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertSame(['body' => '<p>Done.</p>'], $view['content']);
        self::assertTrue($view['content_available']);
        self::assertNull($view['progress']);
    }

    public function testSuspendedEnrolmentIsRefusedWithConflict(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'reading', content: ['body' => '<p>No.</p>']);
        $this->seedEnrolment(self::LEARNER_ID, $courseId, 'suspended');

        $this->expectException(ConflictException::class);

        $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);
    }

    /**
     * A free preview is the lesson its author chose to show, not the
     * no-access placeholder. The resource path reaches the route here as it
     * does for an enrolled learner, and the route signs it; the raw path is
     * never in a response (FreePreviewTest drives that through the route).
     */
    public function testGuestReceivesTheFreePreviewPresentation(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'resource', freePreview: true, content: [
            'files' => [['path' => 'lesson-files/private.pdf', 'name' => 'Handout', 'size_bytes' => 2048]],
        ]);

        $view = $this->getLessonForLearner->execute(Principal::guest(), $lessonId);

        self::assertSame(
            ['files' => [['name' => 'Handout', 'size_bytes' => 2048, 'path' => 'lesson-files/private.pdf']]],
            $view['content'],
        );
        self::assertTrue($view['content_available']);
        self::assertTrue($view['free_preview']);
        self::assertNull($view['progress']);
    }

    /**
     * A lesson type that never declared what its preview discloses is
     * previewed through its no-access presentation. Fail closed: a type
     * written before PresentsFreePreview existed decided only what a reader
     * without access may see, and that is the one answer it ever gave.
     */
    public function testATypeThatDeclaresNoPreviewIsPreviewedThroughItsNoAccessPresentation(): void
    {
        $courseId = $this->seedCourse();
        $moduleId = $this->seedModule($courseId);
        $lessonId = $this->seedLesson($courseId, $moduleId, 'third_party', freePreview: true, content: ['secret' => 'join link']);

        $preview = $this->getLessonForLearner->execute(Principal::guest(), $lessonId);
        self::assertSame(['secret' => null], $preview['content']);
        self::assertTrue($preview['content_available']);

        $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $enrolled = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);
        self::assertSame(['secret' => 'join link'], $enrolled['content']);
    }

    public function testGuestOnANonPreviewLessonIsNotAuthorized(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'reading', content: ['body' => '<p>Members.</p>']);

        $this->expectException(NotAuthorizedException::class);

        $this->getLessonForLearner->execute(Principal::guest(), $lessonId);
    }

    public function testAuthenticatedCallerWithoutEnrolmentIsNotAuthorized(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'reading', content: ['body' => '<p>Members.</p>']);

        $this->expectException(NotAuthorizedException::class);

        $this->getLessonForLearner->execute(Principal::user(self::OUTSIDER_ID), $lessonId);
    }

    public function testAuthenticatedCallerWithoutEnrolmentReceivesTheSameFreePreview(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'resource', freePreview: true, content: [
            'files' => [['path' => 'lesson-files/private.pdf', 'name' => 'Handout', 'size_bytes' => 2048]],
        ]);

        $view = $this->getLessonForLearner->execute(Principal::user(self::OUTSIDER_ID), $lessonId);

        self::assertSame($this->getLessonForLearner->execute(Principal::guest(), $lessonId)['content'], $view['content']);
        self::assertNull($view['progress']);
    }

    public function testDraftLessonIsNotFoundForLearnersButVisibleToTeachers(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'reading', state: 'draft', content: [
            'body' => '<p>Soon.</p>',
        ]);
        $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->env->authorization->grant(self::TEACHER_ID, Capability::TEACH);

        $teacherView = $this->getLessonForLearner->execute(Principal::user(self::TEACHER_ID), $lessonId);
        self::assertSame(['body' => '<p>Soon.</p>'], $teacherView['content']);

        $this->expectException(EntityNotFoundException::class);

        $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);
    }

    public function testUnpublishedCourseHidesItsPublishedLessons(): void
    {
        $courseId = $this->seedCourse(state: 'draft');
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'reading', content: ['body' => '<p>Hidden.</p>']);

        $this->expectException(EntityNotFoundException::class);

        $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);
    }

    public function testTeacherWithoutEnrolmentHasFullAccess(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'resource', content: [
            'files' => [['path' => 'lesson-files/private.pdf', 'name' => 'Handout', 'size_bytes' => 2048]],
        ]);
        $this->env->authorization->grant(self::TEACHER_ID, Capability::TEACH);

        $view = $this->getLessonForLearner->execute(Principal::user(self::TEACHER_ID), $lessonId);

        // Full access: the presentation includes the private storage path.
        self::assertSame(
            ['files' => [['name' => 'Handout', 'size_bytes' => 2048, 'path' => 'lesson-files/private.pdf']]],
            $view['content'],
        );
        self::assertNull($view['progress']);
    }

    public function testUnknownLessonTypeDegradesToAPlaceholder(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'scorm', content: ['package' => 'scorm/pkg.zip']);
        $this->seedEnrolment(self::LEARNER_ID, $courseId);

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        // The lesson still renders; only the content is a placeholder.
        self::assertNull($view['content']);
        self::assertFalse($view['content_available']);
        self::assertSame('scorm', $view['type']);
        self::assertSame($lessonId, $view['id']);
    }

    public function testUnknownLessonIsNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);

        $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), 987654);
    }

    public function testQuizLessonCarriesTheAttemptSummary(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', content: ['quiz_id' => 1]);
        $quizId = $this->seedQuiz($courseId, $lessonId, maxAttempts: 3, timeLimitSeconds: 600);
        $this->seedQuestion($quizId);
        $this->seedQuestion($quizId);
        $this->seedQuestion($quizId, state: 'draft');
        $enrolmentId = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 1, 'graded', 40.0, false);
        $lastAttemptId = $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 2, 'graded', 80.0, true);

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertIsArray($view['quiz']);
        self::assertSame($quizId, $view['quiz']['id']);
        self::assertSame(60, $view['quiz']['pass_mark']);
        self::assertSame(3, $view['quiz']['max_attempts']);
        self::assertSame(2, $view['quiz']['attempts_used']);
        self::assertSame(1, $view['quiz']['attempts_remaining']);
        self::assertSame(600, $view['quiz']['time_limit_seconds']);
        self::assertSame(2, $view['quiz']['question_count']);
        self::assertSame('after_submit', $view['quiz']['show_results']);
        self::assertEqualsWithDelta(80.0, $view['quiz']['best_percentage'], 0.001);
        self::assertSame([
            'id' => $lastAttemptId,
            'status' => 'graded',
            'percentage' => 80.0,
            'passed' => true,
            'expires_at' => null,
        ], $view['quiz']['last_attempt']);
    }

    public function testTheQuizSummaryCarriesTheThreeFactsAStartControlNeeds(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', content: ['quiz_id' => 1]);
        $quizId = $this->seedQuiz($courseId, $lessonId, policy: [
            'opens_at' => '2026-01-01 09:00:00',
            'closes_at' => '2026-12-31 17:00:00',
            'cooldown_after_first_seconds' => 900,
        ]);
        $enrolmentId = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 1, 'graded', 40.0, false);

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertIsArray($view['quiz']);
        self::assertSame('2026-01-01 09:00:00', $view['quiz']['opens_at']);
        self::assertSame('2026-12-31 17:00:00', $view['quiz']['closes_at']);
        self::assertSame(
            $this->env->clock->now()->modify('+900 seconds')->format('Y-m-d H:i:s'),
            $view['quiz']['cooldown_until'],
            'The start control never invites a refusal it could have predicted.',
        );
    }

    public function testAQuizWithNoCooldownSaysSoRatherThanGuessing(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', content: ['quiz_id' => 1]);
        $quizId = $this->seedQuiz($courseId, $lessonId);
        $enrolmentId = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 1, 'graded', 40.0, false);

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertIsArray($view['quiz']);
        self::assertNull($view['quiz']['opens_at']);
        self::assertNull($view['quiz']['closes_at']);
        self::assertNull($view['quiz']['cooldown_until']);
    }

    /**
     * The reported grade is the two quiz settings composed in the stated
     * order, never a literal maximum over every attempt: attempts_counted
     * filters the set, attempt_aggregation reduces what is left.
     */
    public function testTheReportedGradeFollowsTheQuizAggregationSettings(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', content: ['quiz_id' => 1]);
        $quizId = $this->seedQuiz($courseId, $lessonId, policy: ['attempts_counted' => 'first']);
        $enrolmentId = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 1, 'graded', 40.0, false);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 2, 'graded', 80.0, true);

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertIsArray($view['quiz']);
        self::assertEqualsWithDelta(
            40.0,
            $view['quiz']['best_percentage'],
            0.001,
            '"counted: first" is the first attempt, not the highest of all.',
        );
    }

    /**
     * A substituted aggregation is a derived read with no row of its own,
     * so it travels with the grade or it is invisible. Removing the package
     * that provides average_best_n turned a learner's average of their best
     * three into their single highest attempt, on the gradebook, on their
     * own page and on any certificate keyed off it, with nothing recording
     * that it happened.
     */
    public function testAGradeComputedUnderASubstitutedRuleSaysSo(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', content: ['quiz_id' => 1]);
        $quizId = $this->seedQuiz($courseId, $lessonId, policy: ['attempt_aggregation' => 'average_best_n']);
        $enrolmentId = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 1, 'graded', 40.0, false);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 2, 'graded', 80.0, true);

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertIsArray($view['quiz']);
        self::assertSame('highest', $view['quiz']['grade_method'], 'the rule that actually ran');
        self::assertTrue(
            $view['quiz']['grade_method_substituted'],
            'and the learner-facing payload says it is not the rule the quiz asked for',
        );
        self::assertEqualsWithDelta(80.0, $view['quiz']['best_percentage'], 0.001);
    }

    public function testTheRuleTheQuizAsksForIsNotReportedAsASubstitution(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', content: ['quiz_id' => 1]);
        $quizId = $this->seedQuiz($courseId, $lessonId, policy: ['attempt_aggregation' => 'latest']);
        $enrolmentId = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 1, 'graded', 80.0, true);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 2, 'graded', 40.0, false);

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertIsArray($view['quiz']);
        self::assertSame('latest', $view['quiz']['grade_method']);
        self::assertFalse($view['quiz']['grade_method_substituted']);
        self::assertEqualsWithDelta(40.0, $view['quiz']['best_percentage'], 0.001);
    }

    public function testShowResultsNeverWithholdsPercentagesAndPassFlags(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', content: ['quiz_id' => 1]);
        $quizId = $this->seedQuiz($courseId, $lessonId, maxAttempts: 3, showResults: 'never');
        $enrolmentId = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 1, 'graded', 40.0, false);
        $lastAttemptId = $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 2, 'graded', 80.0, true);

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertIsArray($view['quiz']);
        self::assertSame('never', $view['quiz']['show_results']);
        // Results are withheld; attempt counts stay, they are not results.
        self::assertNull($view['quiz']['best_percentage']);
        self::assertSame(2, $view['quiz']['attempts_used']);
        self::assertSame(1, $view['quiz']['attempts_remaining']);
        // id and status survive so the runner can still offer resume.
        self::assertSame([
            'id' => $lastAttemptId,
            'status' => 'graded',
            'percentage' => null,
            'passed' => null,
            'expires_at' => null,
        ], $view['quiz']['last_attempt']);
    }

    public function testOpenAttemptOnATimedQuizCarriesTheServerDeadline(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', content: ['quiz_id' => 1]);
        $quizId = $this->seedQuiz($courseId, $lessonId, timeLimitSeconds: 600);
        $enrolmentId = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $startedAt = $this->nowString();
        $openAttemptId = $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 1, 'in_progress');

        $this->env->clock->advance('PT2M');
        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertIsArray($view['quiz']);
        self::assertIsArray($view['quiz']['last_attempt']);
        self::assertSame($openAttemptId, $view['quiz']['last_attempt']['id']);

        $expected = (new \DateTimeImmutable($startedAt, new \DateTimeZone('UTC')))
            ->modify('+600 seconds')
            ->format('Y-m-d H:i:s');
        self::assertSame($expected, $view['quiz']['last_attempt']['expires_at']);
    }

    public function testOpenAttemptSurfacesAsLastAttemptWithoutCountingAsUsed(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', content: ['quiz_id' => 1]);
        $quizId = $this->seedQuiz($courseId, $lessonId, maxAttempts: 2);
        $enrolmentId = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 1, 'graded', 50.0, false);
        $openAttemptId = $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 2, 'in_progress');

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertIsArray($view['quiz']);
        self::assertSame(1, $view['quiz']['attempts_used']);
        self::assertSame(1, $view['quiz']['attempts_remaining']);
        self::assertEqualsWithDelta(50.0, $view['quiz']['best_percentage'], 0.001);
        self::assertSame([
            'id' => $openAttemptId,
            'status' => 'in_progress',
            'percentage' => null,
            'passed' => null,
            'expires_at' => null,
        ], $view['quiz']['last_attempt']);
    }

    public function testUnlimitedAttemptsAnswerNullAttemptsRemaining(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', content: ['quiz_id' => 1]);
        $quizId = $this->seedQuiz($courseId, $lessonId);
        $enrolmentId = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 1, 'graded', 70.0, true);

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertIsArray($view['quiz']);
        self::assertNull($view['quiz']['max_attempts']);
        self::assertSame(1, $view['quiz']['attempts_used']);
        self::assertNull($view['quiz']['attempts_remaining']);
    }

    public function testAttemptsRemainingNeverGoesNegative(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', content: ['quiz_id' => 1]);
        $quizId = $this->seedQuiz($courseId, $lessonId, maxAttempts: 1);
        $enrolmentId = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 1, 'graded', 30.0, false);
        $this->seedAttempt($quizId, $enrolmentId, self::LEARNER_ID, 2, 'graded', 45.0, false);

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertIsArray($view['quiz']);
        self::assertSame(2, $view['quiz']['attempts_used']);
        self::assertSame(0, $view['quiz']['attempts_remaining']);
    }

    public function testQuizLessonWithoutALinkedQuizAnswersNullQuiz(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', content: ['quiz_id' => 99]);
        $this->seedEnrolment(self::LEARNER_ID, $courseId);

        $view = $this->getLessonForLearner->execute(Principal::user(self::LEARNER_ID), $lessonId);

        self::assertNull($view['quiz']);
        self::assertTrue($view['content_available']);
    }

    public function testGuestPreviewOfAQuizLessonSeesAnUntouchedAttemptSummary(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'quiz', freePreview: true, content: ['quiz_id' => 1]);
        $quizId = $this->seedQuiz($courseId, $lessonId, maxAttempts: 3);
        // Another learner's attempts must not leak into the guest view.
        $otherEnrolment = $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedAttempt($quizId, $otherEnrolment, self::LEARNER_ID, 1, 'graded', 90.0, true);

        $view = $this->getLessonForLearner->execute(Principal::guest(), $lessonId);

        self::assertIsArray($view['quiz']);
        self::assertSame(0, $view['quiz']['attempts_used']);
        self::assertSame(3, $view['quiz']['attempts_remaining']);
        self::assertNull($view['quiz']['best_percentage']);
        self::assertNull($view['quiz']['last_attempt']);
    }

    public function testRouteSmokeThroughTheApiKernel(): void
    {
        $courseId = $this->seedCourse();
        $moduleId = $this->seedModule($courseId);
        $lessonId = $this->seedLesson($courseId, $moduleId, 'reading', content: ['body' => '<p>Route.</p>']);
        $draftLessonId = $this->seedLesson($courseId, $moduleId, 'reading', state: 'draft', content: ['body' => '<p>Draft.</p>']);
        $this->seedEnrolment(self::LEARNER_ID, $courseId);
        $this->seedEnrolment(self::OUTSIDER_ID, $courseId, 'suspended');

        $api = (new CoreKernel($this->env->ports))->apiKernel();

        $ok = $api->handle(new ApiRequest('GET', '/lessons/' . $lessonId, Principal::user(self::LEARNER_ID)));
        self::assertSame(200, $ok->status);
        self::assertNotNull($ok->data);
        self::assertSame($lessonId, $ok->data['id']);
        self::assertSame(['body' => '<p>Route.</p>'], $ok->data['content']);
        self::assertNull($ok->data['progress']);

        $guest = $api->handle(new ApiRequest('GET', '/lessons/' . $lessonId, Principal::guest()));
        self::assertSame(401, $guest->status);
        self::assertSame('application/problem+json', $guest->headers['Content-Type'] ?? null);

        $suspended = $api->handle(new ApiRequest('GET', '/lessons/' . $lessonId, Principal::user(self::OUTSIDER_ID)));
        self::assertSame(409, $suspended->status);
        self::assertSame('application/problem+json', $suspended->headers['Content-Type'] ?? null);

        $draft = $api->handle(new ApiRequest('GET', '/lessons/' . $draftLessonId, Principal::user(self::LEARNER_ID)));
        self::assertSame(404, $draft->status);

        $malformed = $api->handle(new ApiRequest('GET', '/lessons/abc', Principal::user(self::LEARNER_ID)));
        self::assertSame(404, $malformed->status);
    }

    public function testResourceLessonResponseCarriesSignedUrlsAndNeverThePath(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'resource', content: [
            'files' => [['path' => 'lesson-files/private.pdf', 'name' => 'Handout', 'size_bytes' => 2048]],
        ]);
        $this->seedEnrolment(self::LEARNER_ID, $courseId);

        $api = (new CoreKernel($this->env->ports))->apiKernel();
        $response = $api->handle(new ApiRequest('GET', '/lessons/' . $lessonId, Principal::user(self::LEARNER_ID)));

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame([
            'files' => [[
                'name' => 'Handout',
                'size_bytes' => 2048,
                // The FakeStorage signed URL shape: the path round-trips into
                // the URL with the one hour expiry, and never appears raw.
                'url' => $this->env->storage->signedUrl('lesson-files/private.pdf', 3600),
            ]],
        ], $response->data['content']);
        self::assertStringNotContainsString(
            '"path"',
            json_encode($response->data, JSON_THROW_ON_ERROR),
        );
    }

    public function testResourceFreePreviewResponseCarriesSignedUrlsAndNeverThePath(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'resource', freePreview: true, content: [
            'files' => [['path' => 'lesson-files/private.pdf', 'name' => 'Handout', 'size_bytes' => 2048]],
        ]);

        $api = (new CoreKernel($this->env->ports))->apiKernel();
        $response = $api->handle(new ApiRequest('GET', '/lessons/' . $lessonId, Principal::guest()));

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame([
            'files' => [[
                'name' => 'Handout',
                'size_bytes' => 2048,
                'url' => $this->env->storage->signedUrl('lesson-files/private.pdf', 3600),
            ]],
        ], $response->data['content']);
        self::assertStringNotContainsString('"path"', json_encode($response->data, JSON_THROW_ON_ERROR));
    }

    /**
     * Previewing opens only what the author marked: a resource lesson that
     * is not a free preview is still refused to a guest, with nothing of its
     * files in the answer.
     */
    public function testANonPreviewResourceIsRefusedToAGuestAtTheRoute(): void
    {
        $courseId = $this->seedCourse();
        $lessonId = $this->seedLesson($courseId, $this->seedModule($courseId), 'resource', content: [
            'files' => [['path' => 'lesson-files/private.pdf', 'name' => 'Handout', 'size_bytes' => 2048]],
        ]);

        $api = (new CoreKernel($this->env->ports))->apiKernel();
        $response = $api->handle(new ApiRequest('GET', '/lessons/' . $lessonId, Principal::guest()));

        self::assertSame(401, $response->status);
        self::assertStringNotContainsString('private.pdf', json_encode($response->data, JSON_THROW_ON_ERROR));
    }

    private function seedCourse(string $state = 'published'): int
    {
        $n = ++$this->sequence;

        $courseId = $this->env->database->insert('courses', [
            'title' => 'Course ' . $n,
            'slug' => 'course-' . $n,
            'visibility' => 'public',
            'state' => $state,
            'price_mode' => 'free',
            'completion_rule' => 'all_lessons',
            'featured' => 0,
            'created_by' => 1,
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);

        // The per-course rule keys on instructorship, so the fixture's teacher
        // is an instructor of every course the fixture makes.
        $this->courses->assignInstructor($courseId, self::TEACHER_ID, 'lead');

        return $courseId;
    }

    private function seedModule(int $courseId): int
    {
        $n = ++$this->sequence;

        return $this->env->database->insert('modules', [
            'course_id' => $courseId,
            'title' => 'Module ' . $n,
            'position' => $n,
            'state' => 'published',
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function seedLesson(
        int $courseId,
        int $moduleId,
        string $type,
        string $state = 'published',
        bool $freePreview = false,
        array $content = [],
    ): int {
        $n = ++$this->sequence;

        $lessonId = $this->env->database->insert('lessons', [
            'course_id' => $courseId,
            'module_id' => $moduleId,
            'title' => 'Lesson ' . $n,
            'slug' => 'lesson-' . $n,
            'type' => $type,
            'position' => $n,
            'state' => $state,
            'free_preview' => $freePreview ? 1 : 0,
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);

        $this->env->database->insert('lesson_content', [
            'lesson_id' => $lessonId,
            'content' => json_encode($content, JSON_THROW_ON_ERROR),
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);

        return $lessonId;
    }

    private function seedEnrolment(int $userId, int $courseId, string $status = 'active'): int
    {
        return $this->env->database->insert('enrolments', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'entitlement_id' => ++$this->sequence,
            'status' => $status,
            'enrolled_at' => $this->nowString(),
            'progress_percent' => 0,
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);
    }

    private function seedLessonProgress(
        int $enrolmentId,
        int $lessonId,
        int $userId,
        string $status,
        float $progressPercent,
        int $secondsSpent,
        ?int $positionSeconds,
    ): void {
        $this->env->database->insert('lesson_progress', [
            'enrolment_id' => $enrolmentId,
            'lesson_id' => $lessonId,
            'user_id' => $userId,
            'status' => $status,
            'progress_percent' => $progressPercent,
            'seconds_spent' => $secondsSpent,
            'position_seconds' => $positionSeconds,
            'first_accessed_at' => $this->nowString(),
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);
    }

    /**
     * @param array<string, mixed> $policy the quiz-level columns this quiz
     *                                     carries beyond the basics
     */
    private function seedQuiz(
        int $courseId,
        int $lessonId,
        int $passMark = 60,
        ?int $maxAttempts = null,
        ?int $timeLimitSeconds = null,
        string $showResults = 'after_submit',
        array $policy = [],
    ): int {
        $n = ++$this->sequence;

        return $this->env->database->insert('quizzes', [
            'course_id' => $courseId,
            'lesson_id' => $lessonId,
            'title' => 'Quiz ' . $n,
            'pass_mark' => $passMark,
            'max_attempts' => $maxAttempts,
            'time_limit_seconds' => $timeLimitSeconds,
            'is_final' => 0,
            'show_results' => $showResults,
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
            ...$policy,
        ]);
    }

    private function seedQuestion(int $quizId, string $state = 'published'): int
    {
        $n = ++$this->sequence;

        return $this->env->database->insert('questions', [
            'quiz_id' => $quizId,
            'type' => 'true_false',
            'position' => $n,
            'points' => 1,
            'definition' => json_encode(['prompt' => 'Q' . $n, 'correct' => true], JSON_THROW_ON_ERROR),
            'state' => $state,
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);
    }

    private function seedAttempt(
        int $quizId,
        int $enrolmentId,
        int $userId,
        int $attemptNumber,
        string $status,
        ?float $percentage = null,
        ?bool $passed = null,
    ): int {
        return $this->env->database->insert('attempts', [
            'quiz_id' => $quizId,
            'enrolment_id' => $enrolmentId,
            'user_id' => $userId,
            'attempt_number' => $attemptNumber,
            'status' => $status,
            'started_at' => $this->nowString(),
            'submitted_at' => $status === 'in_progress' ? null : $this->nowString(),
            'percentage' => $percentage,
            'passed' => $passed === null ? null : (int) $passed,
            'created_at' => $this->nowString(),
            'updated_at' => $this->nowString(),
        ]);
    }

    private function nowString(): string
    {
        return $this->env->clock->now()->format('Y-m-d H:i:s');
    }
}
