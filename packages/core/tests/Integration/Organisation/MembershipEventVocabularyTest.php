<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Organisation;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\MembershipEventContext;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipEvent;
use Shondalai\VidyaLMS\Core\Domain\Organisation\MembershipState;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Schema\SchemaDefinition;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The membership event vocabulary, split, and the two clocks beside it.
 *
 * The first slice wrote two keys for the whole of membership. Everything a
 * person or a directory could do to a membership other than create it arrived
 * as `member.updated` with a details payload, so the question the append-only
 * stream exists to answer ("why did this person have access on 3 March") was
 * answered by decoding JSON and comparing a previous_state against a state, per
 * row, in the reader.
 */
final class MembershipEventVocabularyTest extends TestCase
{
    private TestPorts $env;
    private CoreKernel $kernel;
    private int $organisation;
    private int $membership;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->kernel = new CoreKernel($this->env->ports, [
            'organisation_invitation_secret' => 'membership-event-test-secret-0001',
            'organisation_invitation_url' => 'https://example.invalid/membership',
        ]);

        $this->env->users->add(1, 'Site Manager', 'manager@example.test');
        $this->env->users->add(2, 'Sam Ling', 'sam@example.test');
        $this->env->authorization->grant(1, Capability::MANAGE);

        $this->organisation = $this->kernel->organisationStore->create([
            'name' => 'North District',
            'slug' => 'north-district',
            'created_by' => 1,
        ]);

        $added = $this->kernel->organisationMembers->add(Principal::user(1), $this->organisation, [
            'expected_revision' => $this->revision(),
            'user_id' => 2,
            'role_key' => 'org_member',
        ]);
        $this->membership = (int) $added['item']['id'];
    }

    /**
     * The columns are declared in schema/tables.php and nowhere else, and each
     * one of them is nullable or defaulted, because both development sites hold
     * rows written before they existed.
     */
    public function testTheEightNewColumnsAreDeclaredAndNoneOfThemForcesAValueOntoAnExistingRow(): void
    {
        $table = SchemaDefinition::load()->table('org_membership_events');
        $nullable = ['org_unit_id', 'role_key', 'source_ref', 'decision_ref', 'occurred_at', 'recorded_at'];

        foreach ($nullable as $name) {
            self::assertTrue($table->column($name)->nullable, $name . ' must be nullable');
        }

        self::assertSame('manual', $table->column('source')->default);
        self::assertSame('principal', $table->column('actor_type')->default);
    }

    public function testTheTwoClocksAreIndexedForTheReadsThatOrderByThem(): void
    {
        $indexes = array_map(
            static fn (array $columns): string => implode(',', $columns),
            array_map(
                static fn (object $index): array => $index->columns,
                SchemaDefinition::load()->table('org_membership_events')->indexes,
            ),
        );

        self::assertContains('organisation_id,occurred_at', $indexes);
        self::assertContains('subject_id,occurred_at', $indexes);
    }

    /**
     * The creation earns its own key rather than the coarse "member.added", and
     * it carries both clocks from the moment it is written.
     */
    public function testAddingSomebodyWritesJoinedWithBothClocks(): void
    {
        $events = $this->events();

        self::assertCount(1, $events, 'The join, and nothing else.');
        self::assertSame(MembershipEvent::JOINED, $events[0]['event_key']);
        self::assertSame('org_member', $events[0]['role_key']);
        self::assertSame(MembershipEvent::SOURCE_MANUAL, $events[0]['source']);
        self::assertSame(MembershipEvent::ACTOR_PRINCIPAL, $events[0]['actor_type']);
        self::assertNotNull($events[0]['occurred_at']);
        self::assertSame(
            $events[0]['occurred_at'],
            $events[0]['recorded_at'],
            'A console write happened when it was recorded, and the two clocks say so rather than being equal by luck.',
        );
    }

    /**
     * A role change is two facts and therefore two rows: what was taken away
     * and what was given. One word for both is what made "who was given the
     * reporting role last quarter" a scan over decoded payloads.
     */
    public function testAChangedRoleWritesARevokeAndAGrantAgainstOneRevision(): void
    {
        $this->update(['role_key' => 'org_reporter']);
        $events = $this->since(1);

        self::assertSame(
            [MembershipEvent::ROLE_REVOKED, MembershipEvent::ROLE_GRANTED],
            array_column($events, 'event_key'),
        );
        self::assertSame('org_member', $events[0]['role_key'], 'The revoked row names what was displaced.');
        self::assertSame('org_reporter', $events[1]['role_key']);
        self::assertSame(
            $events[0]['organisation_revision'],
            $events[1]['organisation_revision'],
            'One request, one revision, two facts.',
        );
    }

    public function testSuspendingResumingAndRemovingAreThreeDistinctKeys(): void
    {
        $this->update(['state' => MembershipState::SUSPENDED]);
        $this->update(['state' => MembershipState::ACTIVE]);
        $this->update(['state' => MembershipState::LEFT]);

        self::assertSame(
            [MembershipEvent::SUSPENDED, MembershipEvent::RESUMED, MembershipEvent::REMOVED],
            array_column($this->since(1), 'event_key'),
        );
    }

    /**
     * Canvas's two words are stops rather than closures, so both are suspended
     * events and only `left` is a removal. One place answers that question, so
     * the roster path and the console cannot answer it differently.
     */
    public function testInactiveAndCompletedAreStopsAndNotRemovals(): void
    {
        self::assertSame(
            MembershipEvent::SUSPENDED,
            MembershipEvent::forStateChange(MembershipState::ACTIVE, MembershipState::INACTIVE),
        );
        self::assertSame(
            MembershipEvent::SUSPENDED,
            MembershipEvent::forStateChange(MembershipState::ACTIVE, MembershipState::COMPLETED),
        );
        self::assertSame(
            MembershipEvent::REMOVED,
            MembershipEvent::forStateChange(MembershipState::ACTIVE, MembershipState::LEFT),
        );
        self::assertSame(
            MembershipEvent::RESUMED,
            MembershipEvent::forStateChange(MembershipState::INACTIVE, MembershipState::ACTIVE),
        );

        $this->update(['state' => MembershipState::INACTIVE]);

        self::assertSame([MembershipEvent::SUSPENDED], array_column($this->since(1), 'event_key'));
        self::assertSame(MembershipState::INACTIVE, $this->stateOfMembership());
    }

    public function testTheTwoNewStatesAreSettableAndAnUnknownOneIsRefusedByName(): void
    {
        foreach ([MembershipState::INACTIVE, MembershipState::COMPLETED, MembershipState::ACTIVE] as $state) {
            $this->update(['state' => $state]);
            self::assertSame($state, $this->stateOfMembership());
        }

        try {
            $this->update(['state' => 'archived']);
            self::fail('Expected an unknown state to be refused.');
        } catch (ValidationException $refused) {
            self::assertArrayHasKey('state', $refused->errors);
        }
    }

    public function testMovingAUnitAndEditingAnAttributeAreTwoDifferentKeys(): void
    {
        $unit = $this->kernel->organisationUnits->save(Principal::user(1), $this->organisation, null, [
            'expected_revision' => $this->revision(),
            'name' => 'South Wing',
        ]);

        $this->update(['org_unit_id' => (int) $unit['item']['id']]);
        self::assertSame([MembershipEvent::UNIT_CHANGED], array_column($this->since(2), 'event_key'));
        self::assertSame((int) $unit['item']['id'], (int) $this->since(2)[0]['org_unit_id']);

        $before = \count($this->events());
        $this->update(['job_title' => 'Analyst']);

        self::assertSame(
            [MembershipEvent::ATTRIBUTES_UPDATED],
            array_column(\array_slice($this->events(), $before), 'event_key'),
        );
    }

    /**
     * Nothing writes the two coarse keys again. They are named so a reader can
     * tell a pre-split row from a post-split one without a date comparison, and
     * so a later wave cannot reintroduce one by habit.
     */
    public function testTheCoarseKeysAreNeverWrittenAgain(): void
    {
        $this->update(['role_key' => 'org_reporter', 'state' => MembershipState::SUSPENDED, 'job_title' => 'Analyst']);

        foreach ($this->events() as $event) {
            self::assertFalse(
                MembershipEvent::isLegacy((string) $event['event_key']),
                'A post-split write used a pre-split word.',
            );
        }
    }

    /**
     * The two clocks exist to tell a backdated correction from a delayed
     * delivery, so a writer that knows when something happened says so and the
     * store stamps the recording instant beside it.
     */
    public function testAWriterThatKnowsWhenSomethingHappenedKeepsTheTwoClocksApart(): void
    {
        $this->kernel->organisationStore->event(
            $this->kernel->organisationAccess->require(Principal::user(1), $this->organisation, 'people'),
            $this->revision(),
            MembershipEvent::REMOVED,
            2,
            ['membership_id' => $this->membership],
            MembershipEventContext::roster('emp-1', null, 'org_member', '2026-03-03 09:00:00'),
        );

        $event = $this->events()[\count($this->events()) - 1];

        self::assertSame('2026-03-03 09:00:00', $event['occurred_at']);
        self::assertNotSame($event['occurred_at'], $event['recorded_at']);
        self::assertSame(MembershipEvent::SOURCE_ROSTER, $event['source']);
        self::assertSame(MembershipEvent::ACTOR_IMPORTER, $event['actor_type']);
        self::assertSame('emp-1', $event['source_ref']);
    }

    /** @param array<string, mixed> $input */
    private function update(array $input): void
    {
        $this->kernel->organisationMembers->update(
            Principal::user(1),
            $this->organisation,
            $this->membership,
            ['expected_revision' => $this->revision()] + $input,
        );
    }

    /** @return list<array<string, mixed>> */
    private function events(): array
    {
        return $this->env->database->selectAll(
            Query::from('org_membership_events')
                ->where('organisation_id', '=', $this->organisation)
                ->orderBy('id')
        );
    }

    /** @return list<array<string, mixed>> */
    private function since(int $skip): array
    {
        return array_values(\array_slice($this->events(), $skip));
    }

    private function stateOfMembership(): string
    {
        $row = $this->env->database->selectOne(
            Query::from('org_memberships')->where('id', '=', $this->membership)
        );

        return (string) ($row['state'] ?? '');
    }

    private function revision(): int
    {
        $row = $this->env->database->selectOne(
            Query::from('organisations')->where('id', '=', $this->organisation)
        );

        return (int) ($row['revision'] ?? 1);
    }
}
