<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\DatabaseIdempotencyStore;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseIdempotencyKeyRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * One key never answers for two different requests.
 *
 * Both families scope a replay on route name plus principal, and a route name
 * is a constant on the great majority of handlers, so the resource a request
 * names lives only in its path. Until the fingerprint below covered the path,
 * `POST /manage/courses/91/modules` and `POST /manage/courses/92/modules`
 * shared one scope: the second answered 201 carrying the first course's row
 * and wrote nothing at all, which is a dropped write reported as a success.
 * The non-atomic family also computed no fingerprint of any kind, so a changed
 * body under a spent key was replayed rather than refused.
 */
#[CoversClass(IdempotentExecution::class)]
final class IdempotencyRequestFingerprintTest extends TestCase
{
    private const KEY = 'one-key-two-resources';

    public function testOneKeyOnTwoResourcesOfOneRouteIsRefusedRatherThanReplayed(): void
    {
        $env = TestPorts::create();
        $runner = new IdempotentExecution($this->store($env));
        $written = [];
        $write = function (int $course) use (&$written): ApiResponse {
            $written[] = $course;

            return ApiResponse::created(['module_id' => 75 + \count($written), 'course_id' => $course]);
        };

        $first = $runner->run(
            $this->request('POST', '/manage/courses/91/modules'),
            'curriculum.modules.add',
            fn (): ApiResponse => $write(91),
        );
        self::assertSame(201, $first->status);
        self::assertSame(['module_id' => 76, 'course_id' => 91], $first->data);

        try {
            $runner->run(
                $this->request('POST', '/manage/courses/92/modules'),
                'curriculum.modules.add',
                fn (): ApiResponse => $write(92),
            );
            self::fail('A key spent on course 91 must never answer for course 92.');
        } catch (ApiProblem $refusal) {
            self::assertSame(422, $refusal->status);
            self::assertSame(IdempotentExecution::REASON_INPUT_MISMATCH, $refusal->extensions['reason'] ?? null);
            self::assertArrayHasKey('Idempotency-Key', $refusal->extensions['errors'] ?? []);
        }

        // The refusal is what proves the write was not silently dropped: the
        // client is told to use a new key rather than handed course 91's row.
        self::assertSame([91], $written);
        self::assertSame(1, $env->database->count(Query::from('idempotency_keys')));
    }

    public function testAChangedBodyUnderASpentKeyIsRefusedOnTheNonAtomicFamilyToo(): void
    {
        $env = TestPorts::create();
        $runner = new IdempotentExecution($this->store($env));
        $path = '/manage/courses/91/modules';
        $first = $runner->run(
            $this->request('POST', $path, ['title' => 'review-int idem module C']),
            'curriculum.modules.add',
            fn (): ApiResponse => ApiResponse::created(['module_id' => 77]),
        );
        self::assertSame(201, $first->status);

        try {
            $runner->run(
                $this->request('POST', $path, ['title' => 'A different module entirely']),
                'curriculum.modules.add',
                function (): ApiResponse {
                    self::fail('A changed body must not execute under a spent key.');
                },
            );
            self::fail('A changed body under a spent key must be refused.');
        } catch (ApiProblem $refusal) {
            self::assertSame(422, $refusal->status);
            self::assertSame(IdempotentExecution::REASON_INPUT_MISMATCH, $refusal->extensions['reason'] ?? null);
        }
    }

    public function testTheIdenticalRequestStillReplaysExactlyOnceOnBothFamilies(): void
    {
        $env = TestPorts::create();
        $runner = new IdempotentExecution($this->store($env));
        $calls = 0;
        $operation = function () use (&$calls): ApiResponse {
            $calls++;

            return ApiResponse::created(['module_id' => 76]);
        };

        $first = $runner->run($this->request(), 'curriculum.modules.add', $operation);
        $replay = $runner->run($this->request(), 'curriculum.modules.add', $operation);
        self::assertSame(1, $calls);
        self::assertSame($first->data, $replay->data);
        self::assertSame('true', $replay->headers['Idempotent-Replayed'] ?? null);

        $atomicFirst = $runner->runAtomic(
            $this->request('POST', '/manage/grade-cells/5/regrade'),
            'gradebook.cell.regrade',
            $env->database,
            $operation,
        );
        $atomicReplay = $runner->runAtomic(
            $this->request('POST', '/manage/grade-cells/5/regrade'),
            'gradebook.cell.regrade',
            $env->database,
            $operation,
        );
        self::assertSame(2, $calls);
        self::assertSame($atomicFirst->data, $atomicReplay->data);
        self::assertSame('true', $atomicReplay->headers['Idempotent-Replayed'] ?? null);
    }

    public function testAPathFreeAtomicScopeCannotCrossResourcesEither(): void
    {
        $env = TestPorts::create();
        $runner = new IdempotentExecution($this->store($env));
        // The shipped shape this covers is RegradeGradeCellHandler, which
        // passes a constant route to runAtomic and carries the cell id in the
        // path alone, so an identical body on a second cell used to replay.
        $runner->runAtomic(
            $this->request('POST', '/manage/grade-cells/1/regrade', ['reason' => 'Marked in error']),
            'gradebook.cell.regrade',
            $env->database,
            fn (): ApiResponse => ApiResponse::ok(['cell_id' => 1]),
        );

        $this->expectException(ApiProblem::class);
        $runner->runAtomic(
            $this->request('POST', '/manage/grade-cells/2/regrade', ['reason' => 'Marked in error']),
            'gradebook.cell.regrade',
            $env->database,
            function (): ApiResponse {
                self::fail('A key spent on cell 1 must never answer for cell 2.');
            },
        );
    }

    public function testAHandlerThatDeclaresItsInputOwnsItsIdentityAndReplaysAcrossPathSpellings(): void
    {
        $env = TestPorts::create();
        $runner = new IdempotentExecution($this->store($env));
        // The notification studio's scope carries the canonical
        // key|locale|channel digest, so /fr-ca/ and /fr-CA/ are one
        // translation and must replay rather than answer 422.
        $scope = 'nt.draft.put:' . hash('sha256', 'vidyalms.enrolled|fr-CA|email');
        $body = ['source' => ['subject' => 'Bonjour'], 'expected_revision' => 0];
        $declared = ['body' => $body, 'query' => []];
        $first = $runner->run(
            $this->request('PUT', '/manage/notifications/templates/vidyalms.enrolled/fr-ca/email/draft', $body),
            $scope,
            fn (): ApiResponse => ApiResponse::ok(['draft_revision' => 1]),
            $declared,
        );
        $replay = $runner->run(
            $this->request('PUT', '/manage/notifications/templates/vidyalms.enrolled/fr-CA/email/draft', $body),
            $scope,
            function (): ApiResponse {
                self::fail('One translation under two spellings must replay.');
            },
            $declared,
        );

        self::assertSame($first->data, $replay->data);
        self::assertSame('true', $replay->headers['Idempotent-Replayed'] ?? null);
    }

    public function testAnOutcomeStoredWithNoFingerprintIsRefusedRatherThanReplayed(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);
        $runner = new IdempotentExecution($store);
        $scope = 'curriculum.modules.add:42';
        // The shape a row written before this check carried.
        $store->claimOrReplay($scope, self::KEY);
        $store->complete($scope, self::KEY, ['status' => 201, 'data' => ['module_id' => 76]]);

        $this->expectException(ApiProblem::class);
        $runner->run($this->request(), 'curriculum.modules.add', function (): ApiResponse {
            self::fail('An outcome with no fingerprint must not execute or replay.');
        });
    }

    private function store(TestPorts $env): DatabaseIdempotencyStore
    {
        return new DatabaseIdempotencyStore(
            new DatabaseIdempotencyKeyRepository($env->database, $env->clock),
            $env->clock,
        );
    }

    /** @param array<string, mixed> $body */
    private function request(
        string $method = 'POST',
        string $path = '/manage/courses/91/modules',
        array $body = ['title' => 'review-int idem module C'],
    ): ApiRequest {
        return new ApiRequest($method, $path, Principal::user(42), body: $body, idempotencyKey: self::KEY);
    }
}
