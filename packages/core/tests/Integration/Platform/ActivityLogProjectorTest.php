<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Platform\ActivityLogProjector;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Domain\Events\AttemptSubmitted;
use Shondalai\VidyaLMS\Core\Domain\Events\CertificateIssued;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentSuspended;
use Shondalai\VidyaLMS\Core\Domain\Events\LearnerEnrolled;
use Shondalai\VidyaLMS\Core\Domain\Events\LessonCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\SubmissionReceived;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseActivityLogRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class ActivityLogProjectorTest extends TestCase
{
    public function testEveryCoveredEventWritesOneActivityRow(): void
    {
        $env = TestPorts::create();
        $bus = $this->subscribedBus($env);
        $at = $env->clock->now();

        $bus->dispatch(new LearnerEnrolled($at, 3, 5, 9, 11));
        $bus->dispatch(new LessonCompleted($at, 3, 4, 5, 9));
        $bus->dispatch(new CourseCompleted($at, 3, 9, 5));
        $bus->dispatch(new AttemptSubmitted($at, 13, 6, 4, 3, 5, 9, 87.5, true, false));
        $bus->dispatch(new SubmissionReceived($at, 17, 8, 4, 3, 5, 9));
        $bus->dispatch(new CertificateIssued($at, 21, 'CERT-1', 3, 5, 9));

        $rows = $env->database->selectAll(Query::from('activity_log')->orderBy('id'));
        self::assertCount(6, $rows);

        $actions = array_map(static fn (array $row): mixed => $row['action'], $rows);
        self::assertSame([
            'vidyalms.enrolment.created',
            'vidyalms.lesson.completed',
            'vidyalms.course.completed',
            'vidyalms.attempt.submitted',
            'vidyalms.submission.received',
            'vidyalms.certificate.issued',
        ], $actions);
    }

    public function testTheRowCarriesTheIdsThePayloadAndTheEventTime(): void
    {
        $env = TestPorts::create();
        $bus = $this->subscribedBus($env);
        $event = new LessonCompleted($env->clock->now(), 3, 4, 5, 9);

        $bus->dispatch($event);

        $row = $env->database->selectOne(Query::from('activity_log'));
        self::assertNotNull($row);
        self::assertSame(5, (int) $row['user_id']);
        self::assertSame(9, (int) $row['course_id']);
        self::assertSame(4, (int) $row['lesson_id']);
        self::assertSame(3, (int) $row['enrolment_id']);
        self::assertSame('2026-01-15 10:00:00', $row['occurred_at']);
        self::assertIsString($row['context']);
        self::assertSame($event->payload(), json_decode($row['context'], true));
    }

    public function testAnUncoveredEventWritesNothing(): void
    {
        $env = TestPorts::create();
        $projector = new ActivityLogProjector(new DatabaseActivityLogRepository($env->database, $env->clock));

        $projector(new EnrolmentSuspended($env->clock->now(), 3, 5, 9));

        self::assertSame(0, $env->database->count(Query::from('activity_log')));
    }

    public function testEventsWithoutALessonLeaveTheLessonColumnNull(): void
    {
        $env = TestPorts::create();
        $bus = $this->subscribedBus($env);

        $bus->dispatch(new LearnerEnrolled($env->clock->now(), 3, 5, 9, 11));

        $row = $env->database->selectOne(Query::from('activity_log'));
        self::assertNotNull($row);
        self::assertNull($row['lesson_id']);
    }

    private function subscribedBus(TestPorts $env): EventBus
    {
        $bus = new EventBus($env->eventBridge, new NullLogger());
        $projector = new ActivityLogProjector(new DatabaseActivityLogRepository($env->database, $env->clock));
        $projector->subscribe($bus);

        return $bus;
    }
}
