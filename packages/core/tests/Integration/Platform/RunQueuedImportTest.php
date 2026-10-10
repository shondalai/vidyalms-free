<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Provider\Importer\JsonCourseImporter;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * What the host's task runner calls when the scheduled import fires. Cron has
 * no session, so the run acts as the person who queued it, and their rights
 * are checked again at that moment.
 */
final class RunQueuedImportTest extends TestCase
{
    private const ADMIN_ID = 9;
    private const SOURCE = 'imports/9/tea.json';

    private TestPorts $env;
    private CoreKernel $kernel;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::ADMIN_ID, Capability::MANAGE);
        $this->env->authorization->grant(self::ADMIN_ID, Capability::TEACH);
        $this->kernel = new CoreKernel($this->env->ports);
        $this->env->storage->putPrivate(self::SOURCE, json_encode([
            'key' => 'tea',
            'title' => 'Foundations of Tea',
            'modules' => [[
                'key' => 'leaves',
                'title' => 'Leaves',
                'lessons' => [
                    ['key' => 'one', 'title' => 'Read', 'type' => 'reading', 'content' => ['body' => '<p>Steep.</p>']],
                ],
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    public function testTheQueuedRunActsAsThePersonWhoAskedForIt(): void
    {
        $job = $this->queue();

        $ran = $this->kernel->runQueuedImport->execute($job->id);

        self::assertSame(ImportJob::STATUS_COMPLETED, $ran->status, (string) $ran->log);
        self::assertSame(['courses' => 1, 'modules' => 1, 'lessons' => 1], $ran->totals);

        $course = $this->env->database->selectOne(Query::from('courses'));
        self::assertNotNull($course);
        self::assertSame(self::ADMIN_ID, (int) $course['created_by']);

        $instructor = $this->env->database->selectOne(Query::from('course_instructors'));
        self::assertNotNull($instructor);
        self::assertSame(self::ADMIN_ID, (int) $instructor['user_id']);
    }

    public function testAnImportQueuedByAPersonWhoLostManageDoesNotRun(): void
    {
        $job = $this->queue();
        $stripped = TestPorts::create();

        // A fresh authorization stub stands in for "the grant was removed".
        $kernel = new CoreKernel(new Ports(
            database: $this->env->database,
            users: $this->env->users,
            authorization: $stripped->authorization,
            storage: $this->env->storage,
            mailer: $this->env->mailer,
            scheduler: $this->env->scheduler,
            eventBridge: $this->env->eventBridge,
            commerce: $this->env->commerce,
            translator: $this->env->translator,
            cache: $this->env->cache,
            clock: $this->env->clock,
            random: $this->env->random,
            logger: new NullLogger(),
        ));

        try {
            $kernel->runQueuedImport->execute($job->id);
            self::fail('Expected a NotAuthorizedException.');
        } catch (NotAuthorizedException) {
            // Expected.
        }

        $unchanged = $this->kernel->getImportJob->execute(Principal::user(self::ADMIN_ID), $job->id);
        self::assertSame(ImportJob::STATUS_PENDING, $unchanged->status);
        self::assertSame(0, $this->env->database->count(Query::from('courses')));
    }

    public function testAnUnknownJobIdIsNotFound(): void
    {
        $this->expectException(EntityNotFoundException::class);
        $this->kernel->runQueuedImport->execute(4242);
    }

    private function queue(): ImportJob
    {
        return $this->kernel->queueImportJob->execute(
            Principal::user(self::ADMIN_ID),
            JsonCourseImporter::KEY,
            self::SOURCE,
        );
    }
}
