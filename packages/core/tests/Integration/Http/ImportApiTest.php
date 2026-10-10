<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\QueueImportJob;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Importer\JsonCourseImporter;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The /import surface: who may use it, what a real import does versus a
 * preview, and the handover to the scheduler that keeps a long migration off
 * the request.
 */
final class ImportApiTest extends TestCase
{
    private const ADMIN_ID = 9;
    private const OTHER_ID = 3;
    private const SOURCE = 'imports/9/tea.json';

    private TestPorts $env;
    private CoreKernel $kernel;
    private ApiKernel $api;
    private Principal $admin;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->env->authorization->grant(self::ADMIN_ID, Capability::MANAGE);
        $this->env->authorization->grant(self::ADMIN_ID, Capability::TEACH);
        $this->env->authorization->grant(self::OTHER_ID, Capability::TEACH);
        $this->kernel = new CoreKernel($this->env->ports);
        $this->api = $this->kernel->apiKernel();
        $this->admin = Principal::user(self::ADMIN_ID);
        $this->env->storage->putPrivate(self::SOURCE, json_encode([
            'key' => 'tea',
            'title' => 'Foundations of Tea',
            'modules' => [
                [
                    'key' => 'leaves',
                    'title' => 'Leaves',
                    'lessons' => [
                        ['key' => 'one', 'title' => 'Read', 'type' => 'reading', 'content' => ['body' => '<p>Steep.</p>']],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function testARealImportIsAcceptedAndHandedToTheSchedulerRatherThanRunInline(): void
    {
        $response = $this->post(['importer' => JsonCourseImporter::KEY, 'source' => self::SOURCE]);

        self::assertSame(202, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(ImportJob::STATUS_PENDING, $response->data['status']);
        self::assertSame(JsonCourseImporter::KEY, $response->data['importer']);
        self::assertSame(self::SOURCE, $response->data['source']);
        self::assertFalse($response->data['dry_run']);
        self::assertSame(self::ADMIN_ID, $response->data['created_by']);
        self::assertNull($response->data['started_at']);

        $jobId = $response->data['id'];
        self::assertIsInt($jobId);

        self::assertCount(1, $this->env->scheduler->tasks);
        $task = $this->env->scheduler->tasks[0];
        self::assertSame('vidyalms.import.run', $task->type);
        self::assertSame(QueueImportJob::TASK_TYPE, $task->type);
        self::assertSame(['job_id' => $jobId], $task->payload);
        self::assertSame('vidyalms.import.run:' . $jobId, $task->dedupeKey);

        // Nothing has been imported yet: that is the point of queuing.
        self::assertSame(0, $this->env->database->count(Query::from('courses')));

        // The task runner picks it up and the job finishes.
        $ran = $this->kernel->runQueuedImport->execute($jobId);
        self::assertSame(ImportJob::STATUS_COMPLETED, $ran->status, (string) $ran->log);
        self::assertSame(1, $this->env->database->count(Query::from('courses')));
    }

    public function testADryRunAnswersImmediatelyWithoutTouchingTheScheduler(): void
    {
        $response = $this->post([
            'importer' => JsonCourseImporter::KEY,
            'source' => self::SOURCE,
            'dry_run' => true,
        ]);

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(ImportJob::STATUS_PREVIEWED, $response->data['status']);
        self::assertTrue($response->data['dry_run']);
        self::assertSame(['courses' => 1, 'modules' => 1, 'lessons' => 1], $response->data['totals']);
        self::assertNotNull($response->data['finished_at']);

        self::assertSame([], $this->env->scheduler->tasks);
        self::assertSame(0, $this->env->database->count(Query::from('courses')));
    }

    public function testAQueuedJobIsNotStartedTwiceByARedeliveredTask(): void
    {
        $response = $this->post(['importer' => JsonCourseImporter::KEY, 'source' => self::SOURCE]);
        self::assertNotNull($response->data);
        $jobId = $response->data['id'];
        self::assertIsInt($jobId);

        $this->kernel->runQueuedImport->execute($jobId);

        $this->expectExceptionMessage('cannot be started again');
        $this->kernel->runQueuedImport->execute($jobId);
    }

    public function testAnUnknownImporterIsA422NamingWhatIsRegistered(): void
    {
        $response = $this->post(['importer' => 'moodle', 'source' => self::SOURCE]);

        self::assertSame(422, $response->status);
        self::assertProblem($response);
        self::assertNotNull($response->data);
        self::assertIsArray($response->data['errors']);
        self::assertStringContainsString(
            'Registered importers: json-course, moodle-backup, community-quiz, learndash, tutor-lms, lifterlms,'
                . ' shika, guru, oscampus, org-members-csv:in, oneroster-csv-1.2:in.',
            (string) $response->data['errors']['importer'],
        );
        self::assertSame(0, $this->env->database->count(Query::from('import_jobs')));
    }

    /**
     * The catalogue, which had no route at all.
     *
     * Both host adapters read the importer registry in process and each grew
     * its own answer to the same four questions, so a third adapter rewrote
     * about seven hundred lines before it drew a control and a headless client
     * could not discover an importer or its options at all.
     */
    public function testTheImporterCatalogueIsServedAndNamesEachImportersOwnScheme(): void
    {
        $response = $this->api->handle(new ApiRequest('GET', '/import/importers', $this->admin));

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertIsArray($response->data['items']);

        $byKey = [];

        foreach ($response->data['items'] as $item) {
            self::assertIsArray($item);
            $byKey[$item['key']] = $item;
        }

        self::assertSame(
            ['json-course', 'moodle-backup', 'community-quiz', 'org-members-csv:in', 'oneroster-csv-1.2:in'],
            array_keys($byKey),
            'Only verified importers are offered by default.',
        );
        self::assertSame([], $byKey['json-course']['schemes'], 'A file importer answers to no scheme.');
        self::assertFalse(
            $byKey['community-quiz']['organisation_eligible'],
            'An importer that reads the host\'s own tables is not an organisation\'s data.',
        );

        $options = $byKey['org-members-csv:in']['options'];
        self::assertNotSame([], $options, 'The declaration a host draws a control from.');
        self::assertSame(
            ['key', 'label', 'kind', 'help', 'default', 'choices'],
            array_keys($options[0]),
            'ImportOption::toArray() verbatim, which is the shape the contract declares.',
        );
    }

    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    #[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
    public function testExperimentalDatabaseImportersNeedExplicitOptIn(): void
    {
        define('VIDYALMS_ENABLE_EXPERIMENTAL_IMPORTERS', true);
        $response = $this->api->handle(new ApiRequest('GET', '/import/importers', $this->admin));
        self::assertSame(200, $response->status);
        $items = $response->data['items'] ?? [];
        self::assertIsArray($items);
        self::assertSame($this->kernel->registries->importers->keys(), array_column($items, 'key'));
        $byKey = array_column($items, null, 'key');
        self::assertSame(['learndash-db:'], $byKey['learndash']['schemes']);
        self::assertSame(['cms-db:'], $byKey['shika']['schemes']);
    }

    public function testTheImporterCatalogueNeedsManageAndIsNotServedAsAJobId(): void
    {
        $refused = $this->api->handle(new ApiRequest('GET', '/import/importers', Principal::user(self::OTHER_ID)));
        self::assertSame(403, $refused->status);

        // Route matching is first registration wins with no precedence of a
        // literal over a parameter, so the wrong order serves this path to the
        // job handler as job id "importers".
        $served = $this->api->handle(new ApiRequest('GET', '/import/importers', $this->admin));
        self::assertNotNull($served->data);
        self::assertArrayHasKey('items', $served->data);
        self::assertArrayNotHasKey('status', $served->data);
    }

    public function testAMissingImporterOrSourceIsA422(): void
    {
        $missingImporter = $this->post(['source' => self::SOURCE]);
        self::assertSame(422, $missingImporter->status);
        self::assertNotNull($missingImporter->data);
        self::assertIsArray($missingImporter->data['errors']);
        self::assertArrayHasKey('importer', $missingImporter->data['errors']);

        $missingSource = $this->post(['importer' => JsonCourseImporter::KEY]);
        self::assertSame(422, $missingSource->status);
        self::assertNotNull($missingSource->data);
        self::assertIsArray($missingSource->data['errors']);
        self::assertArrayHasKey('source', $missingSource->data['errors']);
    }

    public function testStartingAnImportNeedsTheManageCapability(): void
    {
        $guest = $this->api->handle(new ApiRequest('POST', '/import', Principal::guest(), body: [
            'importer' => JsonCourseImporter::KEY,
            'source' => self::SOURCE,
        ]));
        self::assertSame(401, $guest->status);
        self::assertProblem($guest);

        $teacher = $this->api->handle(new ApiRequest('POST', '/import', Principal::user(self::OTHER_ID), body: [
            'importer' => JsonCourseImporter::KEY,
            'source' => self::SOURCE,
        ]));
        self::assertSame(403, $teacher->status);
        self::assertProblem($teacher);

        self::assertSame(0, $this->env->database->count(Query::from('import_jobs')));
        self::assertSame([], $this->env->scheduler->tasks);
    }

    public function testOneJobIsReadableByIdAndOnlyWithManage(): void
    {
        $created = $this->post(['importer' => JsonCourseImporter::KEY, 'source' => self::SOURCE, 'dry_run' => true]);
        self::assertNotNull($created->data);
        $jobId = $created->data['id'];
        self::assertIsInt($jobId);

        $read = $this->api->handle(new ApiRequest('GET', '/import/' . $jobId, $this->admin));
        self::assertSame(200, $read->status);
        self::assertNotNull($read->data);
        self::assertSame($jobId, $read->data['id']);
        self::assertSame(ImportJob::STATUS_PREVIEWED, $read->data['status']);
        self::assertIsString($read->data['log']);
        self::assertNotNull($read->data['created_at']);

        $denied = $this->api->handle(new ApiRequest('GET', '/import/' . $jobId, Principal::user(self::OTHER_ID)));
        self::assertSame(403, $denied->status);
        self::assertProblem($denied);

        $missing = $this->api->handle(new ApiRequest('GET', '/import/99999', $this->admin));
        self::assertSame(404, $missing->status);
        self::assertProblem($missing);
    }

    /**
     * A task process can be killed: an OOM, a deploy, a worker recycled. The
     * row it leaves behind says "running" and nothing will ever change it, so
     * the screen that polls it polls for ever unless it is told.
     */
    public function testAJobWhoseProcessDiedIsReportedAsStalledInsteadOfPolledForEver(): void
    {
        $created = $this->post(['importer' => JsonCourseImporter::KEY, 'source' => self::SOURCE]);
        self::assertNotNull($created->data);
        $jobId = $created->data['id'];
        self::assertIsInt($jobId);

        // The runner started it and wrote one heartbeat, then the process
        // that was running it went away.
        $startedAt = $this->env->clock->now()->format('Y-m-d H:i:s');
        $this->env->database->update('import_jobs', [
            'status' => ImportJob::STATUS_RUNNING,
            'started_at' => $startedAt,
            'updated_at' => $startedAt,
        ], ['id' => $jobId]);

        $running = $this->api->handle(new ApiRequest('GET', '/import/' . $jobId, $this->admin));
        self::assertNotNull($running->data);
        self::assertSame(ImportJob::STATUS_RUNNING, $running->data['status']);
        self::assertFalse($running->data['stalled'], 'A run that wrote a moment ago is running.');
        self::assertSame($startedAt, $running->data['heartbeat_at']);

        $this->env->clock->advance('PT16M');

        $stalled = $this->api->handle(new ApiRequest('GET', '/import/' . $jobId, $this->admin));
        self::assertNotNull($stalled->data);
        self::assertTrue($stalled->data['stalled']);
        self::assertSame(
            ImportJob::STATUS_RUNNING,
            $stalled->data['status'],
            'Nothing reported a failure, so nothing claims one.',
        );

        $list = $this->api->handle(new ApiRequest('GET', '/import', $this->admin));
        self::assertNotNull($list->data);
        self::assertIsArray($list->data['items']);
        self::assertTrue($list->data['items'][0]['stalled'], 'The history says so as well.');

        // A slow run that writes again is running again, not stale.
        $this->env->database->update(
            'import_jobs',
            ['updated_at' => $this->env->clock->now()->format('Y-m-d H:i:s')],
            ['id' => $jobId],
        );

        $alive = $this->api->handle(new ApiRequest('GET', '/import/' . $jobId, $this->admin));
        self::assertNotNull($alive->data);
        self::assertFalse($alive->data['stalled']);
    }

    public function testAFinishedJobIsNeverStalledHoweverOldItIs(): void
    {
        $created = $this->post(['importer' => JsonCourseImporter::KEY, 'source' => self::SOURCE, 'dry_run' => true]);
        self::assertNotNull($created->data);
        $jobId = $created->data['id'];
        self::assertIsInt($jobId);

        $this->env->clock->advance('P30D');

        $read = $this->api->handle(new ApiRequest('GET', '/import/' . $jobId, $this->admin));
        self::assertNotNull($read->data);
        self::assertSame(ImportJob::STATUS_PREVIEWED, $read->data['status']);
        self::assertFalse($read->data['stalled']);
    }

    public function testTheHistoryIsNewestFirstPagedAndManageOnly(): void
    {
        $first = $this->post(['importer' => JsonCourseImporter::KEY, 'source' => self::SOURCE, 'dry_run' => true]);
        $second = $this->post(['importer' => JsonCourseImporter::KEY, 'source' => self::SOURCE, 'dry_run' => true]);
        self::assertNotNull($first->data);
        self::assertNotNull($second->data);

        $list = $this->api->handle(new ApiRequest('GET', '/import', $this->admin));
        self::assertSame(200, $list->status);
        self::assertNotNull($list->data);
        self::assertSame(2, $list->data['total']);
        self::assertSame(1, $list->data['page']);
        self::assertSame(20, $list->data['per_page']);
        self::assertIsArray($list->data['items']);
        self::assertSame(
            [$second->data['id'], $first->data['id']],
            array_map(static fn (array $job): mixed => $job['id'], $list->data['items']),
        );

        $paged = $this->api->handle(new ApiRequest('GET', '/import', $this->admin, query: ['page' => '2', 'per_page' => '1']));
        self::assertSame(200, $paged->status);
        self::assertNotNull($paged->data);
        self::assertIsArray($paged->data['items']);
        self::assertCount(1, $paged->data['items']);
        self::assertSame($first->data['id'], $paged->data['items'][0]['id']);

        $denied = $this->api->handle(new ApiRequest('GET', '/import', Principal::user(self::OTHER_ID)));
        self::assertSame(403, $denied->status);
        self::assertProblem($denied);
    }

    public function testAReplayedStartDoesNotQueueASecondImport(): void
    {
        $body = ['importer' => JsonCourseImporter::KEY, 'source' => self::SOURCE];

        $first = $this->api->handle(new ApiRequest('POST', '/import', $this->admin, body: $body, idempotencyKey: 'import-once'));
        $second = $this->api->handle(new ApiRequest('POST', '/import', $this->admin, body: $body, idempotencyKey: 'import-once'));

        self::assertSame(202, $first->status);
        self::assertSame(202, $second->status);
        self::assertNotNull($first->data);
        self::assertNotNull($second->data);
        self::assertSame($first->data['id'], $second->data['id']);
        self::assertSame(1, $this->env->database->count(Query::from('import_jobs')));
        self::assertCount(1, $this->env->scheduler->tasks);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): ApiResponse
    {
        return $this->api->handle(new ApiRequest('POST', '/import', $this->admin, body: $body));
    }

    private static function assertProblem(ApiResponse $response): void
    {
        self::assertSame('application/problem+json', $response->headers['Content-Type'] ?? null);
        self::assertNotNull($response->data);
        self::assertSame($response->status, $response->data['status']);
    }
}
