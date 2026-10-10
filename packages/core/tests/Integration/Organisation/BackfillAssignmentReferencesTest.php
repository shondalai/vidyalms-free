<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\BackfillAssignmentReferences;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationLearningStore;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The migration half of assignment_ref, as a core use case both hosts call.
 *
 * The reference is minted rather than derived, so the only thing that makes a
 * backfilled row and a freshly written row indistinguishable is that both
 * came out of the same helper. These cases hold that, and hold the two
 * properties a mint has that a digest does not: a second run must not replace
 * a reference that already exists, and a row taken by a concurrent run must
 * not be overwritten.
 */
final class BackfillAssignmentReferencesTest extends TestCase
{
    public function testEveryAssignmentWrittenBeforeTheColumnGetsAMintedReference(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);
        $this->assignment($env, 1, 5);
        $this->assignment($env, 2, 5);
        $this->assignment($env, 3, 9);

        $dry = (new BackfillAssignmentReferences($store))->execute(true);
        self::assertSame(3, $dry['pending'], 'a dry run counts');
        self::assertSame(0, $dry['minted']);
        self::assertSame([null, null, null], $this->references($env), 'and writes nothing');

        $result = (new BackfillAssignmentReferences($store))->execute();

        self::assertSame(3, $result['minted']);
        self::assertSame(0, $result['pending']);

        $references = $this->references($env);
        self::assertCount(3, array_filter($references, static fn (?string $ref): bool => $ref !== null));
        self::assertCount(3, array_unique($references), 'every reference is its own');

        foreach ($references as $reference) {
            self::assertMatchesRegularExpression('/^[A-Z0-9]{32}$/', (string) $reference);
        }
    }

    public function testTheReferenceIsTheOneTheRunningCodeWouldHaveMinted(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);
        $this->assignment($env, 1, 5);

        (new BackfillAssignmentReferences($store))->execute();
        $backfilled = $this->references($env)[0];

        // The same helper, drawn once more: same length, same alphabet, same
        // source. A host writing SQL of its own is what this refuses.
        self::assertSame(\strlen($store->mintAssignmentRef()), \strlen((string) $backfilled));
    }

    public function testASecondRunLeavesEveryReferenceItAlreadyMintedAlone(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);
        $this->assignment($env, 1, 5);
        $this->assignment($env, 2, 5);

        (new BackfillAssignmentReferences($store))->execute();
        $first = $this->references($env);

        $again = (new BackfillAssignmentReferences($store))->execute();

        self::assertSame(0, $again['minted'], 'a reference is a draw and not a derivation, so it is never redrawn');
        self::assertSame($first, $this->references($env));
    }

    public function testARowTakenByAConcurrentRunIsNotOverwritten(): void
    {
        $env = TestPorts::create();
        $store = $this->store($env);
        $this->assignment($env, 1, 5);

        // The read has happened and the row has since been given a reference
        // by another request, which is the shape of two administrators
        // loading the admin at the same moment.
        $store->giveAssignmentReference(1, 'ALREADYMINTEDBYSOMEBODYELSE00000');

        self::assertSame(0, $store->giveAssignmentReference(1, 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'));
        self::assertSame('ALREADYMINTEDBYSOMEBODYELSE00000', $this->references($env)[0]);
    }

    private function store(TestPorts $env): OrganisationLearningStore
    {
        return new OrganisationLearningStore($env->database, $env->clock, $env->random);
    }

    private function assignment(TestPorts $env, int $id, int $organisationId): void
    {
        $now = $env->clock->now()->format('Y-m-d H:i:s');

        $env->database->insert('seat_assignments', [
            'id' => $id,
            'organisation_id' => $organisationId,
            'pool_id' => 1,
            'membership_id' => $id,
            'user_id' => 900 + $id,
            'state' => 'active',
            'assigned_by' => 1,
            'assigned_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return list<string|null> */
    private function references(TestPorts $env): array
    {
        $rows = $env->database->selectAll(Query::from('seat_assignments')->select(['assignment_ref'])->orderBy('id'));

        return array_map(
            static fn (array $row): ?string => $row['assignment_ref'] === null ? null : (string) $row['assignment_ref'],
            $rows,
        );
    }
}
