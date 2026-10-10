<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\AbandonImportJob;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseImportJobRepository;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The one path by which a job's status becomes "failed" without the importer
 * saying so. It is an operator's decision, it is refused while the run is
 * still writing, and the log says who ended it.
 */
final class AbandonImportJobTest extends TestCase
{
    public function testAStalledRunIsMarkedFailedAndSaysWhoEndedIt(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $jobId = $jobs->create('moodle', 'imports/9/course.mbz', [], 9);

        $jobs->markRunning($jobId);
        $jobs->recordProgress($jobId, 'Course "Tea" created.', ['courses' => 1]);
        $env->clock->advance('PT31M');

        $job = $this->useCase($env)->execute(Principal::user(9), $jobId);

        self::assertSame(ImportJob::STATUS_FAILED, $job->status);
        self::assertSame(['courses' => 1], $job->totals, 'What the run created is kept, not cleared.');
        self::assertStringContainsString('Course "Tea" created.', (string) $job->log);
        self::assertStringContainsString('[error] ' . AbandonImportJob::NOTE, (string) $job->log);
        self::assertNotNull($job->finishedAt);
    }

    public function testARunThatIsStillWritingIsLeftAlone(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $jobId = $jobs->create('moodle', null, [], 9);

        $jobs->markRunning($jobId);
        $env->clock->advance('PT2M');

        try {
            $this->useCase($env)->execute(Principal::user(9), $jobId);
            self::fail('A running job that is still writing must not be endable.');
        } catch (ConflictException $refused) {
            self::assertStringContainsString('still writing', $refused->getMessage());
        }

        self::assertSame(ImportJob::STATUS_RUNNING, $jobs->find($jobId)?->status);
    }

    public function testAFinishedRunIsNotEndedAgain(): void
    {
        $env = TestPorts::create();
        $env->authorization->grant(9, Capability::MANAGE);
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $jobId = $jobs->create('moodle', null, [], 9);

        $jobs->markRunning($jobId);
        $jobs->markCompleted($jobId, 'done', ['courses' => 1]);
        $env->clock->advance('PT31M');

        $this->expectException(ConflictException::class);
        $this->useCase($env)->execute(Principal::user(9), $jobId);
    }

    public function testAUserWithoutTheManageCapabilityMayNotEndARun(): void
    {
        $env = TestPorts::create();
        $jobs = new DatabaseImportJobRepository($env->database, $env->clock);
        $jobId = $jobs->create('moodle', null, [], 9);

        $jobs->markRunning($jobId);
        $env->clock->advance('PT31M');

        $this->expectException(NotAuthorizedException::class);
        $this->useCase($env)->execute(Principal::user(9), $jobId);
    }

    private function useCase(TestPorts $env): AbandonImportJob
    {
        return new AbandonImportJob(
            new DatabaseImportJobRepository($env->database, $env->clock),
            new AccessGuard($env->authorization),
            $env->clock,
        );
    }
}
