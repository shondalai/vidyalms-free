<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Provider\Notification\Trigger\EnrolledTrigger;

/**
 * The automation surface through the real routed kernel: every route it
 * declares, and every refusal it promises.
 *
 * The refusals are the point. A rule builder whose save accepts a trigger the
 * fan-out cannot resolve a subject from, a policy pair that cancels itself, or
 * a cooldown of zero on a rule that emits events, has moved three failures out
 * of the author's browser and into somebody's production site.
 */
final class AutomationRoutesTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    /* ── The catalogue ──────────────────────────────────────────────── */

    public function testTheCatalogueIsTheRegistriesAndNotAHardCodedList(): void
    {
        $response = $this->fixture->call('GET', '/manage/automations/catalogue', $this->fixture->manager());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(
            ['triggers', 'conditions', 'actions', 'retry_policies', 'failure_policies', 'anchors', 'limits'],
            array_keys($response->data),
        );

        $actionKeys = array_column($response->data['actions'], 'key');
        sort($actionKeys);
        self::assertSame(
            [
                'add_to_cohort',
                'clear_tag',
                'create_review_task',
                'emit_event',
                'enrol',
                'notify',
                'remove_from_cohort',
                'set_tag',
                'unenrol',
            ],
            $actionKeys,
            'the nine free actions, and nothing the free build does not ship',
        );

        self::assertNotSame([], $response->data['retry_policies']);
        self::assertNotSame([], $response->data['failure_policies']);
        self::assertGreaterThan(0, $response->data['limits']['cooldown_seconds_default']);
    }

    public function testEveryOfferedTriggerCanResolveASubject(): void
    {
        $response = $this->fixture->call('GET', '/manage/automations/catalogue', $this->fixture->manager());

        self::assertNotNull($response->data);
        self::assertNotSame([], $response->data['triggers']);

        $offered = array_column($response->data['triggers'], 'key');

        foreach ($response->data['triggers'] as $trigger) {
            self::assertContains($trigger['kind'], ['event', 'sweep'], $trigger['key'] . ' is one of the two kinds');
        }

        self::assertNotContains(
            'vidyalms.mail.organisation_invitation',
            $offered,
            'a direct-outbox identity declares no subject, so the fan-out could resolve none',
        );
        self::assertContains(EnrolledTrigger::KEY, $offered);
    }

    public function testTheCatalogueRefusesAGuestAndALearner(): void
    {
        self::assertSame(
            401,
            $this->fixture->call('GET', '/manage/automations/catalogue', Principal::guest())->status,
        );
        self::assertSame(
            403,
            $this->fixture->call('GET', '/manage/automations/catalogue', $this->fixture->learner())->status,
        );
    }

    /* ── Authoring ──────────────────────────────────────────────────── */

    public function testCreatingARuleNeedsAnIdempotencyKeyAndAnswersFourHundredWithout(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/automation-rules',
            $this->fixture->manager(),
            $this->rule(),
        );

        self::assertSame(400, $response->status, 'a write that commits its claim requires the header');
    }

    public function testARuleIsCreatedDisabledUnarmedAndAtVersionOne(): void
    {
        $created = $this->create();

        self::assertSame(201, $created->status);
        self::assertNotNull($created->data);
        self::assertSame('disabled', $created->data['state']);
        self::assertNull($created->data['armed_by']);
        self::assertNull($created->data['armed_digest']);
        self::assertSame(1, $created->data['version']);
        self::assertFalse($created->data['active']);
        self::assertCount(1, $created->data['actions']);
        self::assertSame(0, $created->data['actions'][0]['step_index']);
        self::assertSame([], $created->data['missing_keys']);
    }

    public function testAStoredParamsDocumentComesBackUnwrappedWithItsVersionBesideIt(): void
    {
        $id = $this->createdId();
        $read = $this->fixture->call('GET', '/manage/automation-rules/' . $id, $this->fixture->manager());

        self::assertNotNull($read->data);
        $step = $read->data['actions'][0];
        self::assertSame(
            ['tag' => 'welcomed'],
            $step['params'],
            'the stored-shape envelope is an implementation detail and never reaches the wire',
        );
        self::assertSame(1, $step['params_version'], 'the version travels in its own field');
        self::assertSame(1, $read->data['conditions_version']);
        self::assertSame(1, $read->data['definition_version']);
    }

    public function testAFieldTheOperationDoesNotNameIsRefusedByNameRatherThanDropped(): void
    {
        $body = $this->rule();
        $body['queued_count'] = 99;
        $response = $this->fixture->call(
            'POST',
            '/manage/automation-rules',
            $this->fixture->manager(),
            $body,
            [],
            'key-unknown-field',
        );

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('queued_count', $response->data['errors']);
    }

    public function testAnExpectedVersionOnACreateIsRefused(): void
    {
        $body = $this->rule();
        $body['expected_version'] = 1;

        self::assertSame(
            422,
            $this->fixture->call('POST', '/manage/automation-rules', $this->fixture->manager(), $body, [], 'k1')
                ->status,
        );
    }

    public function testAnUnknownTriggerIsRefusedAtSaveBecauseTheRuleCouldNeverFanOut(): void
    {
        $body = $this->rule();
        $body['trigger_key'] = 'vidyalms.trigger.nothing_registers_this';
        $response = $this->fixture->call(
            'POST',
            '/manage/automation-rules',
            $this->fixture->manager(),
            $body,
            [],
            'k-t',
        );

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('trigger_key', $response->data['errors']);
    }

    public function testAnUnknownActionLeavesTheRuleDegradedRatherThanUnsavable(): void
    {
        $body = $this->rule();
        $body['actions'] = [['action_key' => 'teleport_the_learner']];
        $response = $this->fixture->call(
            'POST',
            '/manage/automation-rules',
            $this->fixture->manager(),
            $body,
            [],
            'k-a',
        );

        self::assertSame(201, $response->status, 'removing a package must not make its rule unsavable');
        self::assertNotNull($response->data);
        self::assertSame('degraded', $response->data['state']);
        self::assertSame(['teleport_the_learner'], $response->data['missing_keys']);
        self::assertTrue($response->data['actions'][0]['missing']);
    }

    public function testADegradedRuleCannotBeArmed(): void
    {
        $manager = $this->fixture->manager();
        $body = $this->rule();
        $body['actions'] = [['action_key' => 'teleport_the_learner']];
        $created = $this->fixture->call('POST', '/manage/automation-rules', $manager, $body, [], 'k-degraded');
        self::assertNotNull($created->data);

        $armed = $this->fixture->call(
            'POST',
            '/manage/automation-rules/' . $created->data['id'] . '/arm',
            $manager,
            ['expected_version' => 1, 'acknowledgement' => 'this_rule_may_act_on_people'],
            [],
            'k-arm-degraded',
        );

        self::assertSame(422, $armed->status, 'a rule naming a key nothing answers may not be armed');
    }

    public function testAnInertPolicyPairIsRefusedAtSave(): void
    {
        $manager = $this->fixture->manager();
        $inert = $this->rule();
        $inert['actions'] = [[
            'action_key' => 'set_tag',
            'params' => ['tag' => 'vip'],
            'retry_policy_key' => 'external_default',
            'failure_policy_key' => 'continue',
        ]];
        $response = $this->fixture->call('POST', '/manage/automation-rules', $manager, $inert, [], 'k-i');
        self::assertSame(422, $response->status, 'a failure policy may not silently cancel a retry ladder');
    }

    public function testARuleThatEmitsEventsAtCooldownZeroIsRefused(): void
    {
        $body = $this->rule();
        $body['cooldown_seconds'] = 0;
        $body['actions'] = [['action_key' => 'emit_event', 'params' => ['event_key' => 'vidyalms.custom.thing']]];

        $response = $this->fixture->call(
            'POST',
            '/manage/automation-rules',
            $this->fixture->manager(),
            $body,
            [],
            'k-loop',
        );

        self::assertSame(422, $response->status, 'a two-rule cycle at cooldown zero is invisible to every other guard');
    }

    public function testTheListIsPagedAndFiltersOnTheActionARuleUses(): void
    {
        $this->create();
        $manager = $this->fixture->manager();

        $all = $this->fixture->call('GET', '/manage/automation-rules', $manager);
        self::assertSame(200, $all->status);
        self::assertNotNull($all->data);
        self::assertSame(['items', 'total', 'page', 'per_page'], array_keys($all->data));
        self::assertSame(1, $all->data['total']);

        $matching = $this->fixture->call('GET', '/manage/automation-rules', $manager, [], ['action_key' => 'set_tag']);
        self::assertNotNull($matching->data);
        self::assertSame(1, $matching->data['total']);

        $other = $this->fixture->call('GET', '/manage/automation-rules', $manager, [], ['action_key' => 'notify']);
        self::assertNotNull($other->data);
        self::assertSame(0, $other->data['total'], 'the action filter is a real predicate and not a no-op');
    }

    public function testOneRuleReadsBackAndAMissingOneIsFourOhFour(): void
    {
        $id = $this->createdId();
        $manager = $this->fixture->manager();

        $one = $this->fixture->call('GET', '/manage/automation-rules/' . $id, $manager);
        self::assertSame(200, $one->status);
        self::assertNotNull($one->data);
        self::assertSame($id, $one->data['id']);
        self::assertCount(1, $one->data['versions']);

        self::assertSame(404, $this->fixture->call('GET', '/manage/automation-rules/987654', $manager)->status);
    }

    public function testAnEditMakesANewVersionAndAStaleExpectedVersionIsAConflict(): void
    {
        $id = $this->createdId();
        $manager = $this->fixture->manager();

        $edit = $this->fixture->call(
            'PATCH',
            '/manage/automation-rules/' . $id,
            $manager,
            ['title' => 'Welcome pack, revised', 'expected_version' => 1],
            [],
            'k-edit',
        );

        self::assertSame(200, $edit->status);
        self::assertNotNull($edit->data);
        self::assertSame('Welcome pack, revised', $edit->data['title']);
        self::assertSame(2, $edit->data['version']);

        $stale = $this->fixture->call(
            'PATCH',
            '/manage/automation-rules/' . $id,
            $manager,
            ['title' => 'Third writer', 'expected_version' => 1],
            [],
            'k-stale',
        );

        self::assertSame(409, $stale->status, 'two operators editing one rule do not overwrite each other silently');
    }

    public function testArchivingCancelsTheQueueAndKeepsTheHistory(): void
    {
        $id = $this->createdId();
        $response = $this->fixture->call(
            'DELETE',
            '/manage/automation-rules/' . $id,
            $this->fixture->manager(),
            [],
            [],
            'k-archive',
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('archived', $response->data['state']);
        self::assertSame(0, $response->data['cancelled_queue_rows']);
        self::assertArrayHasKey('runs_retained', $response->data);
    }

    /* ── Arming ─────────────────────────────────────────────────────── */

    public function testArmingIsStatedRatherThanInferredAndRecordsThePerson(): void
    {
        $id = $this->createdId();
        $manager = $this->fixture->manager();

        $silent = $this->fixture->call(
            'POST',
            '/manage/automation-rules/' . $id . '/arm',
            $manager,
            ['expected_version' => 1],
            [],
            'k-arm-silent',
        );
        self::assertSame(422, $silent->status, 'arming is an explicit act the request states');

        $armed = $this->fixture->call(
            'POST',
            '/manage/automation-rules/' . $id . '/arm',
            $manager,
            ['expected_version' => 1, 'acknowledgement' => 'this_rule_may_act_on_people'],
            [],
            'k-arm',
        );

        self::assertSame(200, $armed->status);
        self::assertNotNull($armed->data);
        self::assertSame(ManagementFixture::MANAGER_ID, $armed->data['armed_by']);
        self::assertNotNull($armed->data['armed_at']);
        self::assertIsString($armed->data['armed_digest']);
        self::assertSame(64, \strlen($armed->data['armed_digest']));
    }

    public function testArmingNeedsTheVersionTheAuthorLastReadAndDisarmingClearsIt(): void
    {
        $id = $this->createdId();
        $manager = $this->fixture->manager();

        $stale = $this->fixture->call(
            'POST',
            '/manage/automation-rules/' . $id . '/arm',
            $manager,
            ['expected_version' => 7, 'acknowledgement' => 'this_rule_may_act_on_people'],
            [],
            'k-arm-stale',
        );
        self::assertSame(409, $stale->status);

        $this->fixture->call(
            'POST',
            '/manage/automation-rules/' . $id . '/arm',
            $manager,
            ['expected_version' => 1, 'acknowledgement' => 'this_rule_may_act_on_people'],
            [],
            'k-arm-2',
        );

        $disarmed = $this->fixture->call(
            'POST',
            '/manage/automation-rules/' . $id . '/disarm',
            $manager,
            ['expected_version' => 1],
            [],
            'k-disarm',
        );

        self::assertSame(200, $disarmed->status);
        self::assertNotNull($disarmed->data);
        self::assertNull($disarmed->data['armed_by']);
    }

    /* ── The rehearsal ──────────────────────────────────────────────── */

    public function testARehearsalNamesItsSubjectsAndIsMarkedAsADryRun(): void
    {
        $id = $this->createdId();
        $manager = $this->fixture->manager();

        self::assertSame(
            400,
            $this->fixture->call(
                'POST',
                '/manage/automation-rules/' . $id . '/preview',
                $manager,
                ['subject_ids' => [ManagementFixture::LEARNER_ID]],
            )->status,
            'a rehearsal writes a run row, so its key is required',
        );

        self::assertSame(
            422,
            $this->fixture->call(
                'POST',
                '/manage/automation-rules/' . $id . '/preview',
                $manager,
                [],
                [],
                'k-preview-empty',
            )->status,
            'a rehearsal over everybody is a fan-out with real cost',
        );

        $preview = $this->fixture->call(
            'POST',
            '/manage/automation-rules/' . $id . '/preview',
            $manager,
            ['subject_ids' => [ManagementFixture::LEARNER_ID]],
            [],
            'k-preview',
        );

        self::assertSame(200, $preview->status);
        self::assertNotNull($preview->data);
        self::assertTrue($preview->data['dry_run']);
        self::assertSame(1, $preview->data['matched_count']);
        self::assertCount(1, $preview->data['subjects']);
        self::assertSame(ManagementFixture::LEARNER_ID, $preview->data['subjects'][0]['subject_id']);
        self::assertIsArray($preview->data['steps']);
    }

    public function testARehearsalIsARunAndIsListedAsOne(): void
    {
        $id = $this->createdId();
        $manager = $this->fixture->manager();
        $this->fixture->call(
            'POST',
            '/manage/automation-rules/' . $id . '/preview',
            $manager,
            ['subject_ids' => [ManagementFixture::LEARNER_ID]],
            [],
            'k-preview-run',
        );

        $runs = $this->fixture->call('GET', '/manage/automation-rules/' . $id . '/runs', $manager);

        self::assertSame(200, $runs->status);
        self::assertNotNull($runs->data);
        self::assertSame(1, $runs->data['total']);
        self::assertTrue($runs->data['items'][0]['dry_run'], 'the rehearsal is visible rather than hidden');

        $real = $this->fixture->call(
            'GET',
            '/manage/automation-rules/' . $id . '/runs',
            $manager,
            [],
            ['dry_run' => 'false'],
        );
        self::assertNotNull($real->data);
        self::assertSame(0, $real->data['total']);
    }

    public function testTheActionLogOfOneRunReadsBackAndAnotherRulesRunIsFourOhFour(): void
    {
        $id = $this->createdId();
        $manager = $this->fixture->manager();
        $this->fixture->call(
            'POST',
            '/manage/automation-rules/' . $id . '/preview',
            $manager,
            ['subject_ids' => [ManagementFixture::LEARNER_ID]],
            [],
            'k-preview-log',
        );

        $runs = $this->fixture->call('GET', '/manage/automation-rules/' . $id . '/runs', $manager);
        self::assertNotNull($runs->data);
        $runId = $runs->data['items'][0]['id'];

        $log = $this->fixture->call(
            'GET',
            '/manage/automation-rules/' . $id . '/runs/' . $runId . '/actions',
            $manager,
        );

        self::assertSame(200, $log->status);
        self::assertNotNull($log->data);
        self::assertSame(['items', 'total', 'page', 'per_page'], array_keys($log->data));

        self::assertSame(
            404,
            $this->fixture->call(
                'GET',
                '/manage/automation-rules/' . $id . '/runs/987654/actions',
                $manager,
            )->status,
        );
    }

    /* ── Stored rehearsals ──────────────────────────────────────────── */

    public function testAStoredTestIsWrittenInTheGrammarThatAlreadyExists(): void
    {
        $id = $this->createdId();
        $manager = $this->fixture->manager();

        $unknown = $this->fixture->call(
            'POST',
            '/manage/automation-rules/' . $id . '/tests',
            $manager,
            [
                'name' => 'Invented assertion',
                'given' => ['subjects' => [ManagementFixture::LEARNER_ID]],
                'changed' => [],
                'expectations' => ['engine' => [['kind' => 'the_vibes_were_right']]],
            ],
            [],
            'k-test-bad',
        );

        self::assertSame(422, $unknown->status, 'a comparison language invented by omission is refused by name');

        // Armed, because a rehearsal of a rule nobody has authorised executes
        // no step and a stored test over one can only ever be vacuous.
        self::assertSame(
            200,
            $this->fixture->call(
                'POST',
                '/manage/automation-rules/' . $id . '/arm',
                $manager,
                ['expected_version' => 1, 'acknowledgement' => 'this_rule_may_act_on_people'],
                [],
                'k-test-arm',
            )->status,
        );

        $saved = $this->fixture->call(
            'POST',
            '/manage/automation-rules/' . $id . '/tests',
            $manager,
            [
                'name' => 'One learner is tagged',
                'given' => ['subjects' => [ManagementFixture::LEARNER_ID]],
                'changed' => [],
                'expectations' => ['engine' => [['kind' => 'step_executed', 'step_index' => 0]]],
            ],
            [],
            'k-test',
        );

        self::assertSame(200, $saved->status);
        self::assertNotNull($saved->data);
        self::assertSame('One learner is tagged', $saved->data['name']);
        self::assertSame(1, $saved->data['rule_version']);
        self::assertSame(1, $saved->data['expectations_version']);

        $listed = $this->fixture->call('GET', '/manage/automation-rules/' . $id . '/tests', $manager);
        self::assertSame(200, $listed->status);
        self::assertNotNull($listed->data);
        self::assertSame(1, $listed->data['total']);

        $run = $this->fixture->call(
            'POST',
            '/manage/automation-rules/' . $id . '/tests/' . $saved->data['id'] . '/run',
            $manager,
            [],
            [],
            'k-test-run',
        );

        self::assertSame(200, $run->status);
        self::assertNotNull($run->data);
        self::assertIsArray($run->data['failures']);

        // The result and not merely one of the three words. Accepting any of
        // them is what let the runner read "assert" while the save validated
        // "kind" for a whole wave: a test written to the published grammar
        // saved with a 200 and then scored its assertion as unknown, and this
        // case agreed with it.
        self::assertSame(
            'passed',
            $run->data['result'],
            'the stored test was written in the grammar AutomationExpectations publishes: '
                . implode('; ', $run->data['failures']),
        );
        self::assertSame([], $run->data['failures']);
    }

    public function testEveryAutomationRouteRefusesALearner(): void
    {
        $id = $this->createdId();
        $learner = $this->fixture->learner();

        foreach (
            [
                ['GET', '/manage/automation-rules'],
                ['GET', '/manage/automation-rules/' . $id],
                ['GET', '/manage/automation-rules/' . $id . '/runs'],
                ['GET', '/manage/automation-rules/' . $id . '/tests'],
            ] as [$method, $path]
        ) {
            self::assertSame(403, $this->fixture->call($method, $path, $learner)->status, $method . ' ' . $path);
        }

        self::assertSame(
            403,
            $this->fixture->call(
                'POST',
                '/manage/automation-rules',
                $learner,
                $this->rule(),
                [],
                'k-learner',
            )->status,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function rule(): array
    {
        return [
            'title' => 'Welcome pack',
            'trigger_key' => EnrolledTrigger::KEY,
            'actions' => [[
                'action_key' => 'set_tag',
                'params' => ['tag' => 'welcomed'],
                'retry_policy_key' => 'none',
                'failure_policy_key' => 'park',
            ]],
        ];
    }

    private function create(): \Shondalai\VidyaLMS\Core\Http\ApiResponse
    {
        return $this->fixture->call(
            'POST',
            '/manage/automation-rules',
            $this->fixture->manager(),
            $this->rule(),
            [],
            'k-create-' . bin2hex(random_bytes(4)),
        );
    }

    private function createdId(): int
    {
        $created = $this->create();
        self::assertSame(201, $created->status);
        self::assertNotNull($created->data);

        return (int) $created->data['id'];
    }
}
