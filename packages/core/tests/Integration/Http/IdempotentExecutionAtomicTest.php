<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\DatabaseIdempotencyStore;
use Shondalai\VidyaLMS\Core\Application\Shared\IdempotencyStore;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseIdempotencyKeyRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/** Real database/store integration, with no CMS or certificate route coupling. */
final class IdempotentExecutionAtomicTest extends TestCase
{
    public function testSuccessCommitsMutationAndResponseAndReplayDoesNotRunAgain(): void
    {
        $env = $this->environment();
        $databaseStore = $this->store($env);
        $store = $this->createMock(IdempotencyStore::class);
        $store->expects(self::exactly(2))->method('claimOrReplay')->willReturnCallback(
            function (string $scope, string $key) use ($env, $databaseStore): ?array {
                self::assertTrue($env->database->pdo()->inTransaction());

                return $databaseStore->claimOrReplay($scope, $key);
            },
        );
        $store->expects(self::once())->method('complete')->willReturnCallback(
            function (string $scope, string $key, array $outcome) use ($env, $databaseStore): void {
                self::assertTrue($env->database->pdo()->inTransaction());
                $databaseStore->complete($scope, $key, $outcome);
            },
        );
        $store->expects(self::never())->method('release');
        $runner = new IdempotentExecution($store);
        $calls = 0;
        $operation = function () use ($env, &$calls): ApiResponse {
            $calls++;
            self::assertTrue($env->database->pdo()->inTransaction());
            // An aggregate can nest its transaction within the atomic route.
            $id = $env->database->transactional(
                fn (): int => $env->database->insert('atomic_probe', ['value' => 'committed']),
            );

            return ApiResponse::created(['id' => $id, 'revision' => 2]);
        };

        $first = $runner->runAtomic($this->request(), 'certificate.template.create', $env->database, $operation);
        $replay = $runner->runAtomic($this->request(), 'certificate.template.create', $env->database, $operation);

        self::assertSame(1, $calls);
        self::assertSame(201, $replay->status);
        self::assertSame($first->data, $replay->data);
        self::assertSame(['Idempotent-Replayed' => 'true'], $replay->headers);
        self::assertSame(1, $env->database->count(Query::from('atomic_probe')));
        self::assertSame(1, $env->database->count(Query::from('idempotency_keys')->where('status', '=', 'completed')));
        self::assertFalse($env->database->pdo()->inTransaction());
    }

    public function testOperationFailureRollsBackNestedMutationAndClaimAndAllowsSameKeyRetry(): void
    {
        $env = $this->environment();
        $runner = new IdempotentExecution($this->store($env));
        $failure = new \RuntimeException('Aggregate failure');

        try {
            $runner->runAtomic(
                $this->request(),
                'certificate.template.create',
                $env->database,
                function () use ($env, $failure): ApiResponse {
                    $env->database->transactional(fn (): int => $env->database->insert('atomic_probe', ['value' => 'rolled back']));

                    throw $failure;
                },
            );
            self::fail('The aggregate failure must escape.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        $this->assertNoWrites($env);
        $retried = $runner->runAtomic($this->request(), 'certificate.template.create', $env->database, fn (): ApiResponse =>
            ApiResponse::created(['id' => $env->database->insert('atomic_probe', ['value' => 'retry'])]));
        self::assertSame(201, $retried->status);
        self::assertSame(1, $env->database->count(Query::from('atomic_probe')));
    }

    public function testReplayPreservesEmptyAndNumericKeyedObjectsWithoutTurningListsIntoObjects(): void
    {
        $env = $this->environment();
        $runner = new IdempotentExecution($this->store($env));
        $data = [
            'template_revision' => 4,
            'item' => [
                'data' => new \stdClass(),
                'numeric_map' => (object) ['0' => 'zero', '1' => ['nested' => new \stdClass()]],
                'items' => [],
                'options' => [['data' => new \stdClass()], ['values' => [1, 2]]],
            ],
        ];
        $first = $runner->runAtomic(
            $this->request(),
            'certificate.element.create',
            $env->database,
            fn (): ApiResponse => ApiResponse::created($data),
        );
        $replay = $runner->runAtomic(
            $this->request(),
            'certificate.element.create',
            $env->database,
            function (): ApiResponse {
                self::fail('A completed response must not execute again.');
            },
        );

        self::assertSame($first->status, $replay->status);
        self::assertSame(
            json_encode($first->data, JSON_THROW_ON_ERROR),
            json_encode($replay->data, JSON_THROW_ON_ERROR),
        );
    }

    public function testAtomicReplayChecksCanonicalInputWithoutAddingItToTheScope(): void
    {
        $env = $this->environment();
        $runner = new IdempotentExecution($this->store($env));
        $request = $this->request();
        $input = ['body' => ['name' => 'Original', 'ids' => [1, 2]], 'expected_revision' => 4];
        $first = $runner->runAtomic(
            $request,
            'certificate.write',
            $env->database,
            fn (): ApiResponse => ApiResponse::ok(['revision' => 5]),
            $input,
        );
        $never = function (): ApiResponse {
            self::fail('A completed key must never run another operation.');
        };
        $reordered = ['expected_revision' => 4, 'body' => ['ids' => [1, 2], 'name' => 'Original']];
        $replay = $runner->runAtomic($request, 'certificate.write', $env->database, $never, $reordered);
        self::assertSame($first->data, $replay->data);

        foreach (
            [
            ['body' => ['name' => 'Changed', 'ids' => [1, 2]], 'expected_revision' => 4],
            ['body' => ['name' => 'Original', 'ids' => [2, 1]], 'expected_revision' => 4],
            ['body' => ['name' => 'Original', 'ids' => [1, 2]], 'expected_revision' => 5],
            ['body' => ['name' => 'Original', 'ids' => [1, 2]], 'expected_revision' => '4'],
            ] as $different
        ) {
            try {
                $runner->runAtomic($request, 'certificate.write', $env->database, $never, $different);
                self::fail('A key must not acknowledge changed input as saved.');
            } catch (ApiProblem $failure) {
                // 422 rather than 409: a changed body under a spent key is
                // permanently unprocessable, not a contended state that a
                // later retry could win.
                self::assertSame(422, $failure->status);
                self::assertSame(
                    IdempotentExecution::REASON_INPUT_MISMATCH,
                    $failure->extensions['reason'] ?? null,
                );
                self::assertArrayHasKey('Idempotency-Key', $failure->extensions['errors'] ?? []);
            }
        }

        self::assertSame(1, $env->database->count(Query::from('idempotency_keys')));
        self::assertSame($first->data, $runner->runAtomic(
            $request,
            'certificate.write',
            $env->database,
            $never,
            $input,
        )->data);
    }

    public function testCompletionFailureRollsBackMutationClaimAndEvenAPartiallyStoredOutcome(): void
    {
        $env = $this->environment();
        $databaseStore = $this->store($env);
        $failure = new \RuntimeException('Completion failure');
        $store = $this->createMock(IdempotencyStore::class);
        $store->method('claimOrReplay')->willReturnCallback($databaseStore->claimOrReplay(...));
        $store->expects(self::once())->method('complete')->willReturnCallback(
            function (string $scope, string $key, array $outcome) use ($databaseStore, $failure): void {
                $databaseStore->complete($scope, $key, $outcome);

                throw $failure;
            },
        );
        $store->expects(self::never())->method('release');
        $runner = new IdempotentExecution($store);

        try {
            $runner->runAtomic($this->request(), 'certificate.template.create', $env->database, fn (): ApiResponse =>
                ApiResponse::created(['id' => $env->database->insert('atomic_probe', ['value' => 'rolled back'])]));
            self::fail('A completion failure must escape.');
        } catch (\RuntimeException $caught) {
            self::assertSame($failure, $caught);
        }

        $this->assertNoWrites($env);
        $retry = new IdempotentExecution($databaseStore);
        self::assertSame(204, $retry->runAtomic(
            $this->request(),
            'certificate.template.create',
            $env->database,
            ApiResponse::noContent(...),
        )->status);
        $replayed = $retry->runAtomic($this->request(), 'certificate.template.create', $env->database, function (): ApiResponse {
            self::fail('A stored no-content response must replay.');
        });
        self::assertSame(204, $replayed->status);
        self::assertNull($replayed->data);
    }

    public function testRequiredAndOversizedKeysAreRejectedBeforeAnyStoreOrMutationCall(): void
    {
        $env = $this->environment();
        $store = $this->createMock(IdempotencyStore::class);
        $store->expects(self::never())->method('claimOrReplay');
        $store->expects(self::never())->method('complete');
        $store->expects(self::never())->method('release');
        $runner = new IdempotentExecution($store);

        // An absent header and a present but unusable one are different
        // failures and answer differently: 400 for the first, 422 for the
        // second. Both are refused before the store or the mutation runs.
        foreach ([null, '', " \t\n"] as $key) {
            try {
                $runner->runAtomic($this->request($key), 'certificate.template.create', $env->database, function (): ApiResponse {
                    self::fail('An invalid key must not execute a mutation.');
                });
                self::fail('A missing key must be rejected.');
            } catch (ApiProblem $failure) {
                self::assertSame(400, $failure->status);
            }
        }

        foreach ([str_repeat('x', 191), str_repeat('é', 96)] as $key) {
            try {
                $runner->runAtomic($this->request($key), 'certificate.template.create', $env->database, function (): ApiResponse {
                    self::fail('An invalid key must not execute a mutation.');
                });
                self::fail('An oversized key must be rejected.');
            } catch (ValidationException $failure) {
                self::assertArrayHasKey('Idempotency-Key', $failure->errors);
            }
        }

        $this->assertNoWrites($env);
    }

    public function testLargestActorsFitTheExactScopeLimitWithoutTruncationOrCrossActorReplay(): void
    {
        $env = $this->environment();
        $runner = new IdempotentExecution($this->store($env));
        $route = str_repeat('r', 100 - 1 - \strlen((string) PHP_INT_MAX));
        $key = str_repeat('k', 190);

        foreach ([PHP_INT_MAX, PHP_INT_MAX - 1] as $actor) {
            $response = $runner->runAtomic($this->request($key, $actor), $route, $env->database, fn (): ApiResponse =>
                ApiResponse::created(['actor' => $actor]));
            self::assertSame(['actor' => $actor], $response->data);
            self::assertSame([], $response->headers);
        }

        $scopes = array_column($env->database->selectAll(Query::from('idempotency_keys')->orderBy('id')), 'scope');
        self::assertSame([$route . ':' . PHP_INT_MAX, $route . ':' . (PHP_INT_MAX - 1)], $scopes);
        self::assertSame(100, \strlen((string) $scopes[0]));

        foreach ([$route . 'x', ''] as $invalidRoute) {
            try {
                $runner->runAtomic($this->request('unused', PHP_INT_MAX), $invalidRoute, $env->database, function (): ApiResponse {
                    self::fail('An invalid route scope must not execute.');
                });
                self::fail('An invalid route scope must be rejected.');
            } catch (\InvalidArgumentException $failure) {
                self::assertStringContainsString('scope of at most 100 bytes', $failure->getMessage());
            }
        }
        self::assertSame(2, $env->database->count(Query::from('idempotency_keys')));
    }

    public function testAnEnclosingTransactionCanRollBackAnAtomicSuccess(): void
    {
        $env = $this->environment();
        $runner = new IdempotentExecution($this->store($env));
        $failure = new \RuntimeException('Outer operation failed');
        $caughtFailure = null;

        try {
            $env->database->transactional(function () use ($runner, $env, $failure): void {
                $runner->runAtomic($this->request(), 'certificate.template.create', $env->database, fn (): ApiResponse =>
                    ApiResponse::created(['id' => $env->database->insert('atomic_probe', ['value' => 'nested'])]));

                throw $failure;
            });
        } catch (\RuntimeException $caught) {
            $caughtFailure = $caught;
        }
        self::assertSame($failure, $caughtFailure);
        $this->assertNoWrites($env);
    }

    public function testLegacyRunStillExecutesWithoutAKeyOrATransaction(): void
    {
        $env = $this->environment();
        $runner = new IdempotentExecution($this->store($env));
        $response = $runner->run($this->request(null), 'existing.route', function () use ($env): ApiResponse {
            self::assertFalse($env->database->pdo()->inTransaction());

            return ApiResponse::noContent();
        });
        self::assertSame(204, $response->status);
        self::assertSame(0, $env->database->count(Query::from('idempotency_keys')));
    }

    private function environment(): TestPorts
    {
        $env = TestPorts::create();
        $env->database->pdo()->exec('CREATE TABLE vidyalms_atomic_probe (id INTEGER PRIMARY KEY AUTOINCREMENT, value TEXT NOT NULL)');

        return $env;
    }

    private function store(TestPorts $env): DatabaseIdempotencyStore
    {
        return new DatabaseIdempotencyStore(
            new DatabaseIdempotencyKeyRepository($env->database, $env->clock),
            $env->clock,
        );
    }

    private function request(?string $key = 'atomic-key', int $actor = 42): ApiRequest
    {
        return new ApiRequest('POST', '/atomic-probe', Principal::user($actor), idempotencyKey: $key);
    }

    private function assertNoWrites(TestPorts $env): void
    {
        self::assertSame(0, $env->database->count(Query::from('atomic_probe')));
        self::assertSame(0, $env->database->count(Query::from('idempotency_keys')));
        self::assertFalse($env->database->pdo()->inTransaction());
    }
}
