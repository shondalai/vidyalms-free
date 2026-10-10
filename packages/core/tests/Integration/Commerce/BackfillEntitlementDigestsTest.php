<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Commerce;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Commerce\BackfillEntitlementDigests;
use Shondalai\VidyaLMS\Core\Application\Commerce\SourceRef;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The migration half of the digest work, as a core use case both hosts call.
 *
 * The derivation has to be the same one the running code uses, or a row
 * hashed by a migration and a row hashed by the repository stop being
 * indistinguishable and the index refuses a legitimate grant on one host
 * while accepting a duplicate on the other.
 */
final class BackfillEntitlementDigestsTest extends TestCase
{
    public function testEveryExistingRowGetsTheDigestTheRunningCodeWouldHaveWritten(): void
    {
        $env = TestPorts::create();
        $this->row($env, 501, 11, Entitlement::SOURCE_PURCHASE, 'wc-order-1-course-11');
        $this->row($env, 502, 12, Entitlement::SOURCE_MANUAL, null);

        $result = (new BackfillEntitlementDigests($env->database, $env->clock))->execute();

        self::assertSame(1, $result['digested']);
        self::assertSame(
            SourceRef::digest(Entitlement::SOURCE_PURCHASE, 'wc-order-1-course-11'),
            $this->digestOf($env, 501),
        );
        // A null reference keeps a null digest, which is what preserves the
        // manual and import paths' reliance on nulls being distinct.
        self::assertNull($this->digestOf($env, 502));
    }

    public function testTheRepairRewritesTheKeyRatherThanTheStatusAlone(): void
    {
        $env = TestPorts::create();
        $ref = 'wc-order-7-course-11';
        $this->row($env, 601, 11, Entitlement::SOURCE_PURCHASE, $ref);
        $this->row($env, 602, 11, Entitlement::SOURCE_PURCHASE, $ref);
        $this->row($env, 603, 11, Entitlement::SOURCE_PURCHASE, $ref);

        $backfill = new BackfillEntitlementDigests($env->database, $env->clock);

        // Step 1 first, and it names what is about to change rather than
        // reporting a count.
        $duplicates = $backfill->findDuplicates();
        self::assertCount(1, $duplicates);
        self::assertSame($ref, $duplicates[0]['source_ref']);
        self::assertCount(3, $duplicates[0]['rows']);
        self::assertSame(601, $duplicates[0]['rows'][0]['user_id'], 'the earliest row is first, which is the one kept');

        $result = $backfill->execute();
        self::assertSame(2, $result['repaired']);

        $rows = $env->database->selectAll(Query::from('entitlements')->orderBy('id'));
        self::assertCount(3, $rows);

        // The earliest row keeps the key and stays active.
        self::assertSame($ref, $rows[0]['source_ref']);
        self::assertSame(Entitlement::STATUS_ACTIVE, $rows[0]['status']);

        // The later two carry rewritten keys, deliberately outside the
        // grammar, and are revoked with the reason on the row rather than
        // only in an event. Without the rewrite the unique index would refuse
        // to be created at all, after the repair had already revoked rows.
        foreach ([1, 2] as $index) {
            self::assertStringStartsWith($ref . '-dup', (string) $rows[$index]['source_ref']);
            self::assertSame(Entitlement::STATUS_REVOKED, $rows[$index]['status']);
            self::assertSame(BackfillEntitlementDigests::REPAIR_REASON, $rows[$index]['revoked_reason']);
        }

        // And every digest is distinct afterwards, which is the property that
        // lets the unique index exist at all.
        $digests = array_map(static fn (array $row): mixed => $row['source_ref_digest'], $rows);
        self::assertCount(3, array_unique($digests));
    }

    public function testADryRunReportsAndWritesNothing(): void
    {
        $env = TestPorts::create();
        $ref = 'wc-order-9-course-11';
        $this->row($env, 701, 11, Entitlement::SOURCE_PURCHASE, $ref);
        $this->row($env, 702, 11, Entitlement::SOURCE_PURCHASE, $ref);

        $result = (new BackfillEntitlementDigests($env->database, $env->clock))->execute(dryRun: true);

        self::assertTrue($result['dry_run']);
        self::assertSame(0, $result['repaired']);
        self::assertSame(2, $result['digested'], 'the count is what a real run would write');
        self::assertCount(1, $result['duplicates']);

        self::assertNull($this->digestOf($env, 701));

        $untouched = $env->database->selectOne(Query::from('entitlements')->where('user_id', '=', 702));
        self::assertNotNull($untouched);
        self::assertSame($ref, $untouched['source_ref'], 'a dry run rewrote a key');
    }

    public function testARunOverAlreadyDigestedRowsIsANoOp(): void
    {
        $env = TestPorts::create();
        $this->row($env, 801, 11, Entitlement::SOURCE_PURCHASE, 'wc-order-3-course-11');

        $backfill = new BackfillEntitlementDigests($env->database, $env->clock);
        $backfill->execute();
        $second = $backfill->execute();

        // Which is what makes it safe to run from an upgrade path that may
        // run twice, and what stops a rerun rewriting a key a first run
        // already repaired.
        self::assertSame(0, $second['digested']);
        self::assertSame(0, $second['repaired']);
    }

    public function testARefundedRowNeverKeepsTheKeyFromTheLiveRepurchase(): void
    {
        $env = TestPorts::create();
        $ref = 'wc-order-900-course-20';
        // The shape both hosts run this against: a refund revoked the first
        // grant and the learner bought the course again, so the revoked row
        // holds the lower id and the live grant holds the higher one.
        $this->row($env, 901, 20, Entitlement::SOURCE_PURCHASE, $ref, Entitlement::STATUS_REVOKED, 'refund');
        $this->row($env, 902, 20, Entitlement::SOURCE_PURCHASE, $ref);

        $result = (new BackfillEntitlementDigests($env->database, $env->clock))->execute();

        self::assertSame(1, $result['repaired']);
        $rows = $env->database->selectAll(Query::from('entitlements')->orderBy('id'));
        self::assertSame(
            (int) $rows[1]['id'],
            $result['duplicates'][0]['kept_id'],
            'the active row keeps the key',
        );

        // The live grant keeps the canonical key and stays active, so the
        // learner still has the course they paid for.
        self::assertSame($ref, $rows[1]['source_ref']);
        self::assertSame(Entitlement::STATUS_ACTIVE, $rows[1]['status']);

        // The refunded row is rekeyed and keeps the reason it was revoked for.
        self::assertStringStartsWith($ref . '-dup', (string) $rows[0]['source_ref']);
        self::assertSame(Entitlement::STATUS_REVOKED, $rows[0]['status']);
        self::assertSame('refund', $rows[0]['revoked_reason'], 'the repair overwrote why the person was refunded');

        self::assertSame(
            1,
            $env->database->count(
                Query::from('entitlements')
                    ->where('course_id', '=', 20)
                    ->where('status', '=', Entitlement::STATUS_ACTIVE),
            ),
            'the learner was left with no active entitlement',
        );
    }

    public function testARepairRewritesTheDigestWithTheKeySoASecondPassIsNotRefused(): void
    {
        $env = TestPorts::create();
        $ref = 'wc-order-950-course-21';
        $this->row($env, 951, 21, Entitlement::SOURCE_PURCHASE, $ref);
        $this->row($env, 952, 21, Entitlement::SOURCE_PURCHASE, $ref);

        // The state a partly migrated site is actually in: one row already
        // carries the digest the organisation path wrote, the other does not.
        $env->database->update('entitlements', [
            'source_ref_digest' => SourceRef::digest(Entitlement::SOURCE_PURCHASE, $ref),
        ], ['user_id' => 952]);

        // Before the fix this threw a unique constraint violation, because the
        // rekeyed row kept the winner's digest and step 4 wrote the same one
        // onto the winner.
        $result = (new BackfillEntitlementDigests($env->database, $env->clock))->execute();

        self::assertSame(1, $result['repaired']);
        self::assertSame(
            0,
            $env->database->count(Query::from('entitlements')->whereNull('source_ref_digest')),
            'a row was left without a digest, so the index protects nothing on it',
        );

        $rows = $env->database->selectAll(Query::from('entitlements')->orderBy('id'));
        $digests = array_map(static fn (array $row): mixed => $row['source_ref_digest'], $rows);
        self::assertCount(2, array_unique($digests));

        foreach ($rows as $row) {
            self::assertSame(
                SourceRef::digest((string) $row['source'], (string) $row['source_ref']),
                $row['source_ref_digest'],
                'a digest no longer derives from the key beside it',
            );
        }
    }

    public function testAFailedRepairLeavesNothingRevoked(): void
    {
        $env = TestPorts::create();
        $ref = 'wc-order-970-course-22';
        $this->row($env, 971, 22, Entitlement::SOURCE_PURCHASE, $ref);
        $this->row($env, 972, 22, Entitlement::SOURCE_PURCHASE, $ref);

        $backfill = new BackfillEntitlementDigests($env->database, $env->clock);
        $duplicates = $backfill->findDuplicates();

        // The transaction is the property under test: a run that throws part
        // way through must not leave a rekeyed, revoked row behind.
        try {
            $env->database->transactional(function () use ($env, $duplicates): void {
                $env->database->update('entitlements', [
                    'status' => Entitlement::STATUS_REVOKED,
                    'source_ref' => SourceRef::duplicateRepairRef($duplicates[0]['source_ref'], 2),
                ], ['user_id' => 972]);

                throw new \RuntimeException('the run aborted half way');
            });
        } catch (\RuntimeException) {
            // Expected.
        }

        $rows = $env->database->selectAll(Query::from('entitlements')->orderBy('id'));
        self::assertSame(Entitlement::STATUS_ACTIVE, $rows[1]['status']);
        self::assertSame($ref, $rows[1]['source_ref']);
    }

    private function row(
        TestPorts $env,
        int $userId,
        int $courseId,
        string $source,
        ?string $sourceRef,
        string $status = Entitlement::STATUS_ACTIVE,
        ?string $revokedReason = null,
    ): void {
        $now = $env->clock->now()->format('Y-m-d H:i:s');

        $env->database->insert('entitlements', [
            'user_id' => $userId,
            'course_id' => $courseId,
            'source' => $source,
            'source_ref' => $sourceRef,
            'status' => $status,
            'revoked_reason' => $revokedReason,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function digestOf(TestPorts $env, int $userId): ?string
    {
        $row = $env->database->selectOne(Query::from('entitlements')->where('user_id', '=', $userId));

        return $row === null || $row['source_ref_digest'] === null ? null : (string) $row['source_ref_digest'];
    }
}
