<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Review;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CompletionRule;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentSuspended;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * Pins the fixes from the adversarial review pass: each test names the
 * finding it guards against.
 */
final class ReviewRegressionTest extends TestCase
{
    private const INSTRUCTOR_ID = 1;
    private const LEARNER_ID = 2;
    private const ADMIN_ID = 9;

    private TestPorts $env;
    private CoreKernel $kernel;
    private Principal $instructor;
    private Principal $learner;
    private Principal $admin;

    private int $courseSequence = 0;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::INSTRUCTOR_ID, Capability::TEACH);
        $this->env->authorization->grant(self::ADMIN_ID, Capability::ENROL_OTHERS);
        $this->env->authorization->grant(self::ADMIN_ID, Capability::MANAGE);
        $this->env->users->add(self::INSTRUCTOR_ID, 'Ivy Instructor');
        $this->env->users->add(self::LEARNER_ID, 'Lena Learner');
        $this->env->users->add(self::ADMIN_ID, 'Ada Admin');

        $this->kernel = new CoreKernel($this->env->ports, [
            'verification_base_url' => 'https://example.test/verify',
        ]);
        $this->instructor = Principal::user(self::INSTRUCTOR_ID);
        $this->learner = Principal::user(self::LEARNER_ID);
        $this->admin = Principal::user(self::ADMIN_ID);
    }

    /**
     * Finding: percentage completion denied at the exact boundary because of
     * IEEE-754 division (29 of 50 lessons at a 58 percent threshold).
     */
    public function testPercentageCompletionSucceedsAtTheExactFloatHostileBoundary(): void
    {
        $course = $this->kernel->createCourse->execute(
            $this->instructor,
            'Boundary Course',
            completionRule: CompletionRule::Percentage,
            completionThreshold: 58,
        );
        $module = $this->kernel->addModule->execute($this->instructor, $course->id, 'M1');

        $lessonIds = [];

        for ($i = 1; $i <= 50; $i++) {
            $lessonIds[] = $this->kernel->addLesson->execute(
                $this->instructor,
                $module->id,
                'Lesson ' . $i,
                'reading',
                ['body' => '<p>Text</p>'],
            )->id;
        }

        $this->kernel->publishCourse->execute($this->instructor, $course->id);
        $enrolment = $this->kernel->selfEnrol->execute($this->learner, $course->id);

        for ($i = 0; $i < 29; $i++) {
            $this->kernel->recordLessonProgress->execute(
                $this->learner,
                $enrolment->id(),
                $lessonIds[$i],
                completed: true,
            );
        }

        $row = $this->env->database->selectOne(Query::from('enrolments')->where('id', '=', $enrolment->id()));
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
        self::assertCount(1, $this->eventsOfType(CourseCompleted::class));
    }

    /**
     * Finding: the completion transition raced; the guarded update must keep
     * CourseCompleted exactly-once, so a recompute over an already completed
     * row dispatches nothing.
     */
    public function testGuardedCompletionUpdateNeverFiresOnAnAlreadyCompletedRow(): void
    {
        [, $enrolmentId, $lessonId] = $this->publishOneLessonCourseAndEnrol();

        $this->kernel->recordLessonProgress->execute($this->learner, $enrolmentId, $lessonId, completed: true);
        self::assertCount(1, $this->eventsOfType(CourseCompleted::class));

        $this->kernel->recomputeCourseProgress->execute($this->admin, $enrolmentId);
        self::assertCount(1, $this->eventsOfType(CourseCompleted::class));
    }

    /**
     * Finding: redelivered events duplicated activity_log rows; the event_ref
     * unique index plus a do-nothing upsert keeps one row per occurrence.
     */
    public function testActivityLogDeduplicatesRedeliveredEvents(): void
    {
        $this->publishOneLessonCourseAndEnrol();

        $event = $this->eventsOfType(LearnerEnrolled::class)[0];
        $before = $this->env->database->count(Query::from('activity_log'));

        // Redeliver the exact occurrence through the bus.
        $this->kernel->events->dispatch($event);

        self::assertSame($before, $this->env->database->count(Query::from('activity_log')));
    }

    /**
     * Finding: future-dated entitlements granted access immediately.
     */
    public function testFutureDatedGrantDefersEnrolmentUntilTheSweep(): void
    {
        $courseId = $this->publishedCourseId();
        $startsAt = $this->env->clock->now()->add(new \DateInterval('P30D'))->format('Y-m-d H:i:s');

        $this->kernel->grantEntitlement->execute(
            $this->admin,
            self::LEARNER_ID,
            $courseId,
            Entitlement::SOURCE_MANUAL,
            null,
            $startsAt,
        );

        self::assertNull($this->env->database->selectOne(
            Query::from('enrolments')->where('user_id', '=', self::LEARNER_ID)->where('course_id', '=', $courseId),
        ));
        self::assertCount(0, $this->eventsOfType(LearnerEnrolled::class));

        // Before the start the sweep does nothing; after it, exactly one enrolment.
        self::assertSame(
            ['expired' => 0, 'suspended' => 0, 'activated' => 0],
            $this->kernel->expireEntitlements->execute(),
        );

        $this->env->clock->advance('P31D');
        self::assertSame(
            ['expired' => 0, 'suspended' => 0, 'activated' => 1],
            $this->kernel->expireEntitlements->execute(),
        );
        self::assertCount(1, $this->eventsOfType(LearnerEnrolled::class));

        // A second sweep is a no-op.
        self::assertSame(
            ['expired' => 0, 'suspended' => 0, 'activated' => 0],
            $this->kernel->expireEntitlements->execute(),
        );
        self::assertCount(1, $this->eventsOfType(LearnerEnrolled::class));
    }

    /**
     * Finding: a future-dated second entitlement counted as covering the
     * course, so a revocation left the enrolment active without access.
     */
    public function testRevokeSuspendsWhenTheOnlyOtherEntitlementHasNotStarted(): void
    {
        $courseId = $this->publishedCourseId();

        $active = $this->kernel->grantEntitlement->execute(
            $this->admin,
            self::LEARNER_ID,
            $courseId,
            Entitlement::SOURCE_MANUAL,
        );

        $startsAt = $this->env->clock->now()->add(new \DateInterval('P30D'))->format('Y-m-d H:i:s');
        $this->kernel->grantEntitlement->execute(
            $this->admin,
            self::LEARNER_ID,
            $courseId,
            Entitlement::SOURCE_SUBSCRIPTION,
            'sub-1',
            $startsAt,
        );

        $this->kernel->revokeEntitlement->execute($this->admin, $active->id());

        $row = $this->env->database->selectOne(
            Query::from('enrolments')->where('user_id', '=', self::LEARNER_ID)->where('course_id', '=', $courseId),
        );
        self::assertNotNull($row);
        self::assertSame('suspended', $row['status']);
        self::assertCount(1, $this->eventsOfType(EnrolmentSuspended::class));
    }

    /**
     * Finding: draft modules and lessons leaked to guests through the
     * curriculum endpoint of a published course.
     */
    public function testDraftUnitsAreInvisibleToGuestsAndVisibleToAuthors(): void
    {
        [$courseId] = $this->publishOneLessonCourseAndEnrol();

        $this->kernel->addModule->execute(
            $this->instructor,
            $courseId,
            'Secret draft module',
            null,
            Module::STATE_DRAFT,
        );
        $publishedModuleId = $this->kernel->getCurriculum->execute($this->instructor, $courseId)[0]->module->id;
        $this->kernel->addLesson->execute(
            $this->instructor,
            $publishedModuleId,
            'Secret draft lesson',
            'reading',
            ['body' => '<p>wip</p>'],
            state: Lesson::STATE_DRAFT,
        );

        $guestJson = json_encode(
            $this->kernel->getCurriculum->execute(Principal::guest(), $courseId),
            JSON_THROW_ON_ERROR,
        );
        self::assertStringNotContainsString('Secret draft', $guestJson);

        $authorJson = json_encode(
            $this->kernel->getCurriculum->execute($this->instructor, $courseId),
            JSON_THROW_ON_ERROR,
        );
        self::assertStringContainsString('Secret draft module', $authorJson);
        self::assertStringContainsString('Secret draft lesson', $authorJson);
    }

    /**
     * Finding: LIKE metacharacters in the catalogue search term acted as
     * wildcards; the term is now a literal substring.
     */
    public function testCatalogueSearchTreatsLikeMetacharactersAsLiterals(): void
    {
        $this->publishedCourseId('Plain Course');
        $this->publishedCourseId('50% Discount Masterclass');

        self::assertSame(2, $this->kernel->getCatalogue->execute(Principal::guest())->total);
        self::assertSame(1, $this->kernel->getCatalogue->execute(Principal::guest(), '%')->total);
        self::assertSame(1, $this->kernel->getCatalogue->execute(Principal::guest(), '50%')->total);
        self::assertSame(0, $this->kernel->getCatalogue->execute(Principal::guest(), 'P_ain')->total);
    }

    /**
     * Finding: submission paths accepted traversal syntax and other users'
     * folders; they must live under the submitter's own prefix.
     */
    public function testSubmissionPathsAreBoundToTheSubmitterAndRejectTraversal(): void
    {
        [$courseId] = $this->publishOneLessonCourseAndEnrol();

        $moduleId = $this->kernel->getCurriculum->execute($this->instructor, $courseId)[0]->module->id;
        $assignmentLesson = $this->kernel->addLesson->execute(
            $this->instructor,
            $moduleId,
            'Essay',
            'assignment',
            [],
        );
        $this->kernel->configureAssignment->execute($this->instructor, $assignmentLesson->id);

        $bad = [
            'submissions/' . self::LEARNER_ID . '/../1/stolen.pdf',
            'submissions/999/foreign.pdf',
            'submissions/' . self::LEARNER_ID . '/..',
            'submissions/' . self::LEARNER_ID . '\\evil.pdf',
        ];

        foreach ($bad as $path) {
            try {
                $this->kernel->submitAssignment->execute($this->learner, $assignmentLesson->id, null, [[
                    'path' => $path,
                    'filename' => 'file.pdf',
                    'size_bytes' => 10,
                    'mime' => 'application/pdf',
                ]]);
                self::fail(sprintf('Path "%s" should have been rejected.', $path));
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('files.0.path', $exception->errors, $path);
            }
        }

        $this->env->storage->putPrivate('submissions/' . self::LEARNER_ID . '/essay.pdf', '%PDF-1.4\nTest essay');
        $submission = $this->kernel->submitAssignment->execute($this->learner, $assignmentLesson->id, null, [[
            'path' => 'submissions/' . self::LEARNER_ID . '/essay.pdf',
            'filename' => 'essay.pdf',
            'size_bytes' => 10,
            'mime' => 'application/pdf',
        ]]);
        self::assertTrue($submission->isSubmitted());
    }

    /**
     * Finding: an oversized Idempotency-Key surfaced as a driver error (500)
     * instead of a validation problem.
     */
    public function testOversizedIdempotencyKeyIsA422(): void
    {
        $courseId = $this->publishedCourseId();

        $response = $this->kernel->apiKernel()->handle(new ApiRequest(
            'POST',
            '/enrolments',
            $this->learner,
            [],
            ['course_id' => $courseId],
            str_repeat('k', 191),
        ));

        self::assertSame(422, $response->status);
        self::assertIsArray($response->data);
        self::assertArrayHasKey('Idempotency-Key', $response->data['errors'] ?? []);
    }

    /**
     * Finding: heartbeat validation errors named internal camelCase
     * parameters instead of the wire fields.
     */
    public function testHeartbeatValidationErrorsUseWireFieldNames(): void
    {
        [, $enrolmentId, $lessonId] = $this->publishOneLessonCourseAndEnrol();

        try {
            $this->kernel->recordLessonProgress->execute($this->learner, $enrolmentId, $lessonId, 150.0);
            self::fail('An out-of-range percentage should be rejected.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('progress_percent', $exception->errors);
        }
    }

    /**
     * @param class-string<DomainEvent> $eventClass
     *
     * @return list<DomainEvent>
     */
    private function eventsOfType(string $eventClass): array
    {
        return array_values(array_filter(
            $this->env->eventBridge->published,
            static fn (DomainEvent $event): bool => $event instanceof $eventClass,
        ));
    }

    /**
     * @return array{0: int, 1: int, 2: int} course id, enrolment id, lesson id
     */
    private function publishOneLessonCourseAndEnrol(): array
    {
        $courseId = $this->publishedCourseId();
        $lessonRow = $this->env->database->selectOne(
            Query::from('lessons')->where('course_id', '=', $courseId),
        );
        self::assertNotNull($lessonRow);

        $enrolment = $this->kernel->selfEnrol->execute($this->learner, $courseId);

        return [$courseId, $enrolment->id(), (int) $lessonRow['id']];
    }

    private function publishedCourseId(string $title = ''): int
    {
        $this->courseSequence++;
        $course = $this->kernel->createCourse->execute(
            $this->instructor,
            $title === '' ? 'Course Number ' . $this->courseSequence : $title,
        );
        $module = $this->kernel->addModule->execute($this->instructor, $course->id, 'M1');
        $this->kernel->addLesson->execute(
            $this->instructor,
            $module->id,
            'Reading',
            'reading',
            ['body' => '<p>Text</p>'],
        );
        $this->kernel->publishCourse->execute($this->instructor, $course->id);

        return $course->id;
    }
}
