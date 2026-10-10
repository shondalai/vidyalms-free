<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Platform\DatabaseIdempotencyStore;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform\DatabaseIdempotencyKeyRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Schema\ColumnType;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

final class DatabaseIdempotencyStoreTest extends TestCase
{
    public function testExpiredWorkerCannotCompleteOrReleaseItsReplacement(): void
    {
        $env = TestPorts::create();
        $old = $this->store($env);
        $replacement = $this->store($env);
        self::assertNull($old->claimOrReplay('enrol', 'race'));
        $env->clock->advance('PT61M');
        self::assertNull($replacement->claimOrReplay('enrol', 'race'));
        try {
            $old->complete('enrol', 'race', ['owner' => 'old']);
            self::fail('An expired worker must not publish over a replacement claim.');
        } catch (ConflictException) {
            $old->release('enrol', 'race');
        }
        $replacement->complete('enrol', 'race', ['owner' => 'new']);
        self::assertSame(['owner' => 'new'], $this->store($env)->claimOrReplay('enrol', 'race'));
    }

    public function testFirstClaimReturnsNull(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);

        self::assertNull($store->claimOrReplay('enrol', 'key-1'));

        $row = $env->database->selectOne(Query::from('idempotency_keys')->where('idem_key', '=', 'key-1'));
        self::assertNotNull($row);
        self::assertSame('claimed', $row['status']);
    }

    public function testReplayOfACompletedKeyReturnsTheStoredOutcome(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);

        self::assertNull($store->claimOrReplay('enrol', 'key-1'));
        $store->complete('enrol', 'key-1', ['enrolment_id' => 7, 'nested' => ['a' => 1]]);

        self::assertSame(
            ['enrolment_id' => 7, 'nested' => ['a' => 1]],
            $store->claimOrReplay('enrol', 'key-1'),
        );

        $row = $env->database->selectOne(Query::from('idempotency_keys')->where('idem_key', '=', 'key-1'));
        self::assertNotNull($row);
        self::assertSame('completed', $row['status']);
        // The outcome is retained for 24 hours from completion.
        self::assertSame('2026-01-16 10:00:00', $row['expires_at']);
    }

    /**
     * A replay must answer with the response the first call produced, and
     * "the same document written differently" is not that. The outcome column
     * is text for exactly this reason: MySQL's JSON type stores a normalised
     * binary form and hands the keys back in its own order, so a replay came
     * back byte-different from the answer it was replaying. The SQLite harness
     * cannot show that (it stores text either way), so the schema is what this
     * pins down.
     */
    public function testTheOutcomeColumnIsTextSoAReplayIsByteIdentical(): void
    {
        $outcome = SchemaDefinition::load()->tables['idempotency_keys']->column('outcome');

        self::assertSame(
            ColumnType::MediumText,
            $outcome->type,
            'A JSON column reorders the keys of the body a replay has to repeat.',
        );
        self::assertTrue($outcome->nullable);
    }

    public function testAReplayRepeatsTheStoredBodyByteForByte(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);

        // Deliberately not alphabetical, and deliberately mixed depth: this is
        // the shape a normalising column reorders.
        $body = ['zeta' => 1, 'alpha' => ['nested_z' => true, 'nested_a' => null], 'm' => 'x'];

        self::assertNull($store->claimOrReplay('enrol', 'key-order'));
        $store->complete('enrol', 'key-order', $body);

        $replayed = $store->claimOrReplay('enrol', 'key-order');

        self::assertSame(
            json_encode($body, \JSON_THROW_ON_ERROR),
            json_encode($replayed, \JSON_THROW_ON_ERROR),
        );
    }

    public function testInFlightClaimRaisesConflict(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);

        self::assertNull($store->claimOrReplay('enrol', 'key-1'));

        $this->expectException(ConflictException::class);
        $store->claimOrReplay('enrol', 'key-1');
    }

    public function testExpiredClaimIsReclaimed(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);

        self::assertNull($store->claimOrReplay('enrol', 'key-1'));
        $env->clock->advance('PT61M');

        self::assertNull($store->claimOrReplay('enrol', 'key-1'));

        self::assertSame(1, $env->database->count(Query::from('idempotency_keys')));
        $row = $env->database->selectOne(Query::from('idempotency_keys')->where('idem_key', '=', 'key-1'));
        self::assertNotNull($row);
        self::assertSame('claimed', $row['status']);
        self::assertSame('2026-01-15 12:01:00', $row['expires_at']);
    }

    public function testExpiredOutcomeIsNotReplayed(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);
        $store->claimOrReplay('answers:42', 'expired-answer');
        $store->complete('answers:42', 'expired-answer', ['private_answer' => 'Essay']);
        $env->clock->advance('PT24H');

        self::assertNull($store->claimOrReplay('answers:42', 'expired-answer'));
        $row = $env->database->selectOne(Query::from('idempotency_keys')->where('idem_key', '=', 'expired-answer'));
        self::assertNotNull($row);
        self::assertSame('claimed', $row['status']);
        self::assertNull($row['outcome']);
    }

    public function testAClaimJustBeforeExpiryStillConflicts(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);

        self::assertNull($store->claimOrReplay('enrol', 'key-1'));
        $env->clock->advance('PT59M');

        $this->expectException(ConflictException::class);
        $store->claimOrReplay('enrol', 'key-1');
    }

    public function testReleaseFreesTheKeyForANewClaim(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);

        self::assertNull($store->claimOrReplay('enrol', 'key-1'));
        $store->release('enrol', 'key-1');

        self::assertSame(0, $env->database->count(Query::from('idempotency_keys')));
        self::assertNull($store->claimOrReplay('enrol', 'key-1'));
    }

    public function testReleaseNeverDeletesACompletedOutcome(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);

        self::assertNull($store->claimOrReplay('enrol', 'key-1'));
        $store->complete('enrol', 'key-1', ['enrolment_id' => 7]);
        $store->release('enrol', 'key-1');

        self::assertSame(['enrolment_id' => 7], $store->claimOrReplay('enrol', 'key-1'));
    }

    public function testScopesAreIndependent(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);

        self::assertNull($store->claimOrReplay('enrol', 'key-1'));
        self::assertNull($store->claimOrReplay('submit', 'key-1'));
    }

    /**
     * A gateway reconciliation window outlives an hour, and a claim that
     * expires inside it lets a webhook retry re-execute a payment capture.
     * The scope is what decides, because one store serves every caller.
     */
    public function testAScopeMayHoldItsClaimLongerThanTheDefaultHour(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env, ['commerce.gateway' => 'PT72H']);

        self::assertNull($store->claimOrReplay('commerce.gateway', 'key-1'));
        $env->clock->advance('PT61M');

        try {
            $store->claimOrReplay('commerce.gateway', 'key-1');

            self::fail('the gateway claim expired after the default hour');
        } catch (ConflictException) {
            // Expected: the claim is still held.
        }

        // A scope that named no TTL is unaffected by the one that did.
        self::assertNull($store->claimOrReplay('enrol', 'key-1'));
        $env->clock->advance('PT61M');
        self::assertNull($store->claimOrReplay('enrol', 'key-1'));
    }

    public function testAScopeMayKeepItsOutcomeLongerThanTheDefaultDay(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env, [], ['commerce.gateway' => 'P30D']);

        self::assertNull($store->claimOrReplay('commerce.gateway', 'key-1'));
        $store->complete('commerce.gateway', 'key-1', ['captured' => true]);

        $row = $env->database->selectOne(Query::from('idempotency_keys')->where('idem_key', '=', 'key-1'));
        self::assertNotNull($row);
        self::assertSame('2026-02-14 10:00:00', $row['expires_at']);
    }

    /**
     * The scope an HTTP caller actually produces.
     *
     * IdempotentExecution mints "routeName:principalId", so a map keyed by
     * whole scope could name no route anybody reaches: it would have to name
     * one principal at a time, and the configured window would silently never
     * apply. The key is the route family and the longest dotted prefix wins.
     */
    public function testAConfiguredFamilyCoversTheRouteScopesAnHttpCallerMints(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env, ['commerce.gateway' => 'PT72H']);

        self::assertNull($store->claimOrReplay('commerce.gateway.stripe:42', 'key-1'));
        $env->clock->advance('PT61M');

        try {
            $store->claimOrReplay('commerce.gateway.stripe:42', 'key-1');

            self::fail('the gateway claim expired after the default hour');
        } catch (ConflictException) {
            // Expected: the family's window covers the route beneath it.
        }

        // A neighbouring family is untouched, and so is the same route under
        // another principal, which is a different scope and a different row.
        self::assertNull($store->claimOrReplay('enrol.self:42', 'key-1'));
        $env->clock->advance('PT61M');
        self::assertNull($store->claimOrReplay('enrol.self:42', 'key-1'));
    }

    public function testTheLongestMatchingFamilyWins(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env, [], ['commerce' => 'P7D', 'commerce.gateway' => 'P30D']);

        self::assertNull($store->claimOrReplay('commerce.gateway.stripe:42', 'key-1'));
        $store->complete('commerce.gateway.stripe:42', 'key-1', ['captured' => true]);

        $row = $env->database->selectOne(Query::from('idempotency_keys')->where('idem_key', '=', 'key-1'));
        self::assertNotNull($row);
        self::assertSame('2026-02-14 10:00:00', $row['expires_at']);
    }

    /**
     * A family name is matched whole. "commerce" must not cover
     * "commerce_reports", which is a different route with its own window.
     */
    public function testAPartialWordIsNotAFamilyMatch(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env, [], ['commerce' => 'P30D']);

        self::assertNull($store->claimOrReplay('commerce_reports.export:42', 'key-1'));
        $store->complete('commerce_reports.export:42', 'key-1', ['done' => true]);

        $row = $env->database->selectOne(Query::from('idempotency_keys')->where('idem_key', '=', 'key-1'));
        self::assertNotNull($row);
        self::assertSame('2026-01-16 10:00:00', $row['expires_at']);
    }

    /** The host is the only thing that can supply a window, so it has to reach the store. */
    public function testTheKernelPassesTheHostsWindowsToTheStore(): void
    {
        $env = TestPorts::create();
        $kernel = new CoreKernel($env->ports, [
            'idempotency_claim_ttls' => ['commerce.gateway' => 'PT72H'],
            'idempotency_outcome_ttls' => ['commerce.gateway' => 'P30D', 7 => 'ignored', 'bad' => 5],
        ]);
        $store = $kernel->idempotencyStore;

        self::assertNull($store->claimOrReplay('commerce.gateway.stripe:42', 'key-1'));
        $store->complete('commerce.gateway.stripe:42', 'key-1', ['captured' => true]);

        $row = $env->database->selectOne(Query::from('idempotency_keys')->where('idem_key', '=', 'key-1'));
        self::assertNotNull($row);
        self::assertSame('2026-02-14 10:00:00', $row['expires_at']);
    }

    public function testTheShippedDefaultsAreTheOnesEveryScopeStillGets(): void
    {
        self::assertSame('PT1H', DatabaseIdempotencyStore::DEFAULT_CLAIM_TTL);
        self::assertSame('PT24H', DatabaseIdempotencyStore::DEFAULT_OUTCOME_TTL);
    }

    public function testATtlThatIsNotADurationIsRefusedAtConstruction(): void
    {
        $env = TestPorts::create();

        $this->expectException(\InvalidArgumentException::class);

        new DatabaseIdempotencyStore(
            new DatabaseIdempotencyKeyRepository($env->database, $env->clock),
            $env->clock,
            ['commerce.gateway' => 'PT72H'],
            ['commerce.gateway' => '30 days'],
        );
    }

    /**
     * @param array<string, string> $claimTtls
     * @param array<string, string> $outcomeTtls
     */
    private function store(TestPorts $env, array $claimTtls = [], array $outcomeTtls = []): DatabaseIdempotencyStore
    {
        return new DatabaseIdempotencyStore(
            new DatabaseIdempotencyKeyRepository($env->database, $env->clock),
            $env->clock,
            $claimTtls,
            $outcomeTtls,
        );
    }
}
