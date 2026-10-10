<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Assessment\GetCourseReport;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\DatabaseEnrolmentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseCourseRepository;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportRegistry;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\Report\CourseOverviewReport;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class CourseOverviewReportTest extends TestCase
{
    public function testAggregatesTheSeededEnrolments(): void
    {
        $env = TestPorts::create();
        $this->seed($env);
        $env->authorization->grant(1, Capability::VIEW_REPORTS);

        $result = $this->report($env)->generate(ReportContext::create(['course_id' => 1], Principal::user(1)));

        self::assertSame(1, $result['course_id']);
        self::assertSame(3, $result['enrolled_count']);
        self::assertSame(2, $result['active_count']);
        self::assertSame(1, $result['completed_count']);
        self::assertSame(73.33, $result['average_progress']);
        self::assertSame(80.5, $result['average_quiz_score']);
    }

    public function testACourseWithoutEnrolmentsReportsZeroes(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(1, Capability::VIEW_REPORTS);

        $result = $this->report($env)->generate(ReportContext::create(['course_id' => 999], Principal::user(1)));

        self::assertSame(0, $result['enrolled_count']);
        self::assertSame(0, $result['active_count']);
        self::assertSame(0, $result['completed_count']);
        self::assertSame(0.0, $result['average_progress']);
        self::assertNull($result['average_quiz_score']);
    }

    /**
     * The capability check moved out of the provider and into the use case
     * that resolves it, so the refusal is asserted where it now lives.
     *
     * The provider is deliberately not asserted to refuse on its own any more:
     * a provider that checks its own capability is a provider that also has to
     * filter by scope, and the eleventh one forgets the second half. The
     * refusal is stronger here, not weaker, because it now covers every
     * registered report rather than the one that remembered to write it.
     */
    public function testAUserWithoutTheCapabilityIsDenied(): void
    {
        $env = TestPorts::create();
        $this->seed($env);

        $this->expectException(NotAuthorizedException::class);
        $this->reader($env)->execute(Principal::user(2), 1, CourseOverviewReport::KEY);
    }

    public function testAGuestIsDenied(): void
    {
        $env = TestPorts::create();

        $this->expectException(NotAuthorizedException::class);
        $this->reader($env)->execute(Principal::guest(), 1, CourseOverviewReport::KEY);
    }

    /** The same refusal reaches a key nothing registers, so the reasons cannot be read apart. */
    public function testAnUnknownKeyIsAlsoAuthorisedBeforeItIsAnswered(): void
    {
        $env = TestPorts::create();

        $this->expectException(NotAuthorizedException::class);
        $this->reader($env)->execute(Principal::user(2), 1, 'no-such-report');
    }

    public function testAMissingCourseIdIsRejected(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(1, Capability::VIEW_REPORTS);

        $this->expectException(ValidationException::class);
        $this->report($env)->generate(ReportContext::create([], Principal::user(1)));
    }

    private function report(TestPorts $env): CourseOverviewReport
    {
        return new CourseOverviewReport($env->database, $env->translator);
    }

    /** The use case that authorises, resolves and hands the provider a scope. */
    private function reader(TestPorts $env): GetCourseReport
    {
        $registry = new ReportRegistry();
        $registry->register($this->report($env));

        return new GetCourseReport(
            $registry,
            new CourseAccess(
                new AccessGuard($env->authorization),
                new DatabaseCourseRepository($env->database, $env->clock),
                new DatabaseEnrolmentRepository($env->database, $env->clock),
            ),
            $env->translator,
        );
    }

    private function seed(TestPorts $env): void
    {
        $now = $env->clock->now()->format('Y-m-d H:i:s');

        $enrolments = [
            ['user_id' => 1, 'course_id' => 1, 'status' => 'active', 'progress_percent' => 40, 'completed_at' => null],
            ['user_id' => 2, 'course_id' => 1, 'status' => 'active', 'progress_percent' => 80, 'completed_at' => null],
            ['user_id' => 3, 'course_id' => 1, 'status' => 'completed', 'progress_percent' => 100, 'completed_at' => $now],
            ['user_id' => 1, 'course_id' => 2, 'status' => 'active', 'progress_percent' => 10, 'completed_at' => null],
        ];

        $ids = [];

        foreach ($enrolments as $enrolment) {
            $ids[] = $env->database->insert('enrolments', array_merge($enrolment, [
                'entitlement_id' => 1,
                'enrolled_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]));
        }

        $quizAverages = [$ids[0] => 70.5, $ids[1] => null, $ids[2] => 90.5, $ids[3] => 55.0];

        foreach ($quizAverages as $enrolmentId => $quizAverage) {
            $env->database->insert('course_progress', [
                'enrolment_id' => $enrolmentId,
                'quiz_average' => $quizAverage,
                'computed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
