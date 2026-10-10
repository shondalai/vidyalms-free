<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\IssueCertificateOnCourseCompletion;
use Shondalai\VidyaLMS\Core\Domain\Events\CourseCompleted;
use Shondalai\VidyaLMS\Core\Domain\Events\LessonCompleted;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class IssueCertificateOnCourseCompletionTest extends CredentialsTestCase
{
    private IssueCertificateOnCourseCompletion $listener;

    protected function setUp(): void
    {
        parent::setUp();

        $this->listener = new IssueCertificateOnCourseCompletion($this->issueCertificateUseCase());
    }

    public function testReplayedCourseCompletedIssuesExactlyOneCertificate(): void
    {
        $this->seedCourse(7, 'Advanced Weaving');
        $this->seedEnrolment(10, 5, 7);
        $this->eventBus->subscribe(CourseCompleted::class, $this->listener);

        $event = new CourseCompleted($this->ports->clock->now(), 10, 7, 5);

        $this->eventBus->dispatch($event);
        $this->eventBus->dispatch($event);

        self::assertSame(1, $this->ports->database->count(Query::from('certificates')));
        self::assertCount(1, $this->certificateIssuedEvents());

        $certificate = $this->certificates->findActiveByEnrolmentId(10);
        self::assertNotNull($certificate);
        self::assertSame(5, $certificate->userId);
        self::assertSame(7, $certificate->courseId);
    }

    public function testIgnoresEventsOtherThanCourseCompleted(): void
    {
        ($this->listener)(new LessonCompleted($this->ports->clock->now(), 10, 3, 5, 7));

        self::assertSame(0, $this->ports->database->count(Query::from('certificates')));
        self::assertCount(0, $this->certificateIssuedEvents());
    }
}
