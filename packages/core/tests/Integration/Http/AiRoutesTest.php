<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Ai\RecallAiRun;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Registry\Ai\DescribesAiPlacement;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\RouteAvailablePolicy;

/**
 * The AI surface through the real routed kernel, on a build that has bought
 * nothing.
 *
 * Two things are being proved here, and neither is that the happy path works.
 *
 * The first is that a free site gets real answers rather than 404s. The
 * placements render and say why they are unavailable, the policy is drafted,
 * activated and accepted, the routes table refuses an uncapped row, the stop
 * records who set it, and the learner can read what touched their data. Every
 * one of those is a promise the design makes to a site that never buys Pro.
 *
 * The second is the redline. No draft is reachable by a learner read path, no
 * shape carries a score, and `/me/ai-activity` answers for the caller and for
 * nobody else. Those are asserted as refusals, because a redline that is only
 * ever exercised from the happy side is a redline nobody tested.
 */
final class AiRoutesTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testRoutesMatchTheExactScopeAndCannotUseZeroAsAWildcard(): void
    {
        $body = ['task_key' => 'check.schema_validity', 'bridge_key' => 'null', 'max_cost_per_run' => 100];
        $manager = $this->fixture->manager();
        $invalid = $this->fixture->call('POST', '/manage/ai/routes', $manager,
            $body + ['scope_type' => 'course', 'scope_id' => 0], [], 'invalid-scope');
        self::assertSame(422, $invalid->status);
        $created = $this->fixture->call('POST', '/manage/ai/routes', $manager,
            $body + ['scope_type' => 'course', 'scope_id' => 7], [], 'scope-seven');
        self::assertSame(201, $created->status);
        self::assertNotNull($created->data);
        self::assertArrayHasKey('id', $created->data);
        $id = (int) $created->data['id'];
        $this->fixture->call('PATCH', '/manage/ai/routes/' . $id, $manager, ['enabled' => true], [], 'enabled-seven');
        $db = $this->fixture->env->database;
        self::assertSame(1, RouteAvailablePolicy::countRoutes($db, $body['task_key'], 'course', 7));
        self::assertSame(0, RouteAvailablePolicy::countRoutes($db, $body['task_key'], 'course', 9));
        self::assertSame(0, RouteAvailablePolicy::countRoutes($db, $body['task_key'], 'organisation', 7));
        $site = $this->fixture->call('PATCH', '/manage/ai/routes/' . $id, $manager,
            ['scope_type' => 'site', 'scope_id' => 99], [], 'scope-site');
        self::assertNotNull($site->data);
        self::assertArrayHasKey('scope_id', $site->data);
        self::assertSame(0, $site->data['scope_id']);
        self::assertSame(1, RouteAvailablePolicy::countRoutes($db, $body['task_key'], 'course', 9));
        $invalidSwitch = $this->fixture->call('PATCH', '/manage/ai/routes/' . $id, $manager,
            ['scope_type' => 'course'], [], 'invalid-switch');
        self::assertSame(422, $invalidSwitch->status);
    }

    /* ── Placements ─────────────────────────────────────────────────── */

    public function testThePlacementsRouteAnswersOnAFreeSiteAndNamesWhyNothingIsAvailable(): void
    {
        $response = $this->fixture->call('GET', '/ai/placements', $this->fixture->manager());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(
            ['items', 'site_enabled', 'stopped', 'policy_accepted', 'policy_version'],
            array_keys($response->data),
        );
        self::assertFalse($response->data['site_enabled'], 'everything is off by default');
        self::assertFalse($response->data['stopped']);
        self::assertFalse($response->data['policy_accepted']);
        self::assertNull($response->data['policy_version']);

        foreach ($response->data['items'] as $placement) {
            self::assertFalse($placement['available'], $placement['key'] . ' has no enabled route');
            self::assertNotNull($placement['unavailable_reason']);
            self::assertArrayHasKey('mode', $placement['disclosure']);
            self::assertNotContains(
                'score',
                $placement['fillable_fields'],
                'the score field appears in no placement list anywhere in the product',
            );
        }
    }

    public function testAPlacementWhoseAnchorTheBundleDoesNotPublishIsNotOffered(): void
    {
        $all = $this->fixture->call('GET', '/ai/placements', $this->fixture->manager());
        self::assertNotNull($all->data);

        $filtered = $this->fixture->call(
            'GET',
            '/ai/placements',
            $this->fixture->manager(),
            [],
            ['anchor' => 'nothing.this.bundle.publishes'],
        );

        self::assertSame(200, $filtered->status);
        self::assertNotNull($filtered->data);
        self::assertSame([], $filtered->data['items'], 'an unpublished anchor degrades rather than drawing');
    }

    /**
     * A guest reads nothing, and a learner reads the learner-facing placement
     * and nothing else.
     *
     * The learner used to be refused outright, which made the three
     * disclosure strings of the one learner-facing position in the design
     * reachable only by the people they are not addressed to: Article 50(1)
     * asks for the first of them BEFORE the first interaction, and it lived
     * on a payload every learner was answered 403 for.
     */
    public function testThePlacementsRouteRefusesAGuestAndNarrowsALearnerToTheirOwnPanel(): void
    {
        self::assertSame(401, $this->fixture->call('GET', '/ai/placements', Principal::guest())->status);

        $learner = $this->fixture->call('GET', '/ai/placements', $this->fixture->learner());

        self::assertSame(200, $learner->status);
        self::assertNotNull($learner->data);
        self::assertSame(
            ['player_explain_mistake'],
            array_column($learner->data['items'], 'key'),
            'a learner reads the learner-facing placement and no authoring panel',
        );
        self::assertSame(
            'ai.disclosure.player_explain_mistake.before_interaction',
            $learner->data['items'][0]['disclosure']['learner'],
            'and the disclosure they are owed before the first interaction is in it',
        );
    }

    /**
     * The three disclosure strings are text, and reach the surface as text.
     *
     * The contract states them as what a human is shown ("Shown before the
     * first turn, Article 50(1)"), and a placement declares them as catalogue
     * keys the way it declares its label. The catalogue translated label and
     * surface_label and published the disclosure triple raw, so the Article
     * 50(1) line reached a live learner as
     * ai.disclosure.admin_narrative.before_interaction. mode is the one member
     * of the four that is a contract value and stays verbatim.
     */
    public function testTheThreeDisclosureStringsAreTranslatedAndTheModeIsNot(): void
    {
        $this->fixture->env->translator->define(
            'ai.disclosure.player_explain_mistake.before_interaction',
            'A tutor writes this answer, and it can be wrong.',
        );
        $this->fixture->env->translator->define(
            'ai.disclosure.player_explain_mistake.at_read_time',
            'Written with help from a tutor.',
        );
        $this->fixture->env->translator->define(
            'ai.disclosure.player_explain_mistake.at_review',
            'Review the tutor draft before it is kept.',
        );

        $learner = $this->fixture->call('GET', '/ai/placements', $this->fixture->learner());

        self::assertSame(200, $learner->status);
        self::assertNotNull($learner->data);

        $disclosure = $learner->data['items'][0]['disclosure'];

        self::assertSame('A tutor writes this answer, and it can be wrong.', $disclosure['learner']);
        self::assertSame('Written with help from a tutor.', $disclosure['reader']);
        self::assertSame('Review the tutor draft before it is kept.', $disclosure['reviewer']);
        self::assertSame('every_interaction', $disclosure['mode'], 'the mode is a contract value, not copy');

        foreach (['learner', 'reader', 'reviewer'] as $audience) {
            self::assertStringNotContainsString(
                'ai.disclosure.',
                (string) $disclosure[$audience],
                $audience . ' was published as a catalogue key rather than as the text it declares',
            );
        }
    }

    /* ── The data-use policy and its acceptance ─────────────────────── */

    public function testThePolicyReadsNullBeforeASiteOwnerActivatesOne(): void
    {
        $response = $this->fixture->call('GET', '/ai/policy', $this->fixture->learner());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertNull($response->data['policy']);
        self::assertFalse($response->data['accepted']);
        self::assertFalse($response->data['stop']['stopped']);
    }

    public function testDraftingActivatingAndAcceptingAPolicyVersion(): void
    {
        $created = $this->createPolicy();

        self::assertSame(201, $created->status);
        self::assertNotNull($created->data);
        self::assertSame(1, $created->data['version']);
        self::assertSame('draft', $created->data['state'], 'activation is a separate act');

        $activated = $this->fixture->call(
            'PATCH',
            '/manage/ai/policies/1',
            $this->fixture->manager(),
            ['state' => 'active'],
            [],
            'activate-1',
        );

        self::assertSame(200, $activated->status);
        self::assertNotNull($activated->data);
        self::assertSame('active', $activated->data['state']);

        $before = $this->fixture->call('GET', '/ai/policy', $this->fixture->learner());
        self::assertNotNull($before->data);
        self::assertFalse($before->data['accepted']);

        $accepted = $this->fixture->call(
            'PUT',
            '/ai/policy/acceptance',
            $this->fixture->learner(),
            ['version' => 1, 'placement_key' => 'learner_player'],
            [],
            'accept-1',
        );

        self::assertSame(200, $accepted->status);
        self::assertNotNull($accepted->data);
        self::assertTrue($accepted->data['accepted']);
        self::assertSame(1, $accepted->data['accepted_version']);
        self::assertSame(1, $accepted->data['policy']['acceptance_count']);
    }

    public function testAChangedPolicyVersionRePromptsTheSamePerson(): void
    {
        $this->createPolicy();
        $this->fixture->call('PATCH', '/manage/ai/policies/1', $this->fixture->manager(), ['state' => 'active'], [], 'a1');
        $this->fixture->call('PUT', '/ai/policy/acceptance', $this->fixture->learner(), ['version' => 1], [], 'accept-1');

        $this->createPolicy('Second version', 'k2');
        $this->fixture->call('PATCH', '/manage/ai/policies/2', $this->fixture->manager(), ['state' => 'active'], [], 'a2');

        $state = $this->fixture->call('GET', '/ai/policy', $this->fixture->learner());

        self::assertNotNull($state->data);
        self::assertSame(2, $state->data['policy']['version']);
        self::assertFalse($state->data['accepted'], 'a changed policy re-prompts');
        self::assertSame(1, $state->data['accepted_version'], 'and says which version they did accept');
    }

    public function testAcceptingAVersionThatIsNotInForceIsARefusalAndNotASilentConsent(): void
    {
        $this->createPolicy();

        $response = $this->fixture->call(
            'PUT',
            '/ai/policy/acceptance',
            $this->fixture->learner(),
            ['version' => 1],
            [],
            'accept-draft',
        );

        self::assertSame(409, $response->status);
    }

    public function testAcceptanceRequiresAnIdempotencyKey(): void
    {
        $this->createPolicy();
        $this->fixture->call('PATCH', '/manage/ai/policies/1', $this->fixture->manager(), ['state' => 'active'], [], 'a1');

        $response = $this->fixture->call('PUT', '/ai/policy/acceptance', $this->fixture->learner(), ['version' => 1]);

        self::assertSame(400, $response->status, 'a write that commits its claim requires the header');
    }

    public function testAnActivePolicyBodyIsImmutable(): void
    {
        $this->createPolicy();
        $this->fixture->call('PATCH', '/manage/ai/policies/1', $this->fixture->manager(), ['state' => 'active'], [], 'a1');

        $response = $this->fixture->call(
            'PATCH',
            '/manage/ai/policies/1',
            $this->fixture->manager(),
            ['body' => ['based_on_external_models' => 'Rewritten after people accepted it.']],
            [],
            'edit-1',
        );

        self::assertSame(409, $response->status);
    }

    public function testTheRetentionFloorIsEnforcedAtTheWritePath(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/ai/policies',
            $this->fixture->manager(),
            ['title' => 'Short memory', 'body' => 'Thirty days.', 'retention_days' => 30],
            [],
            'short-1',
        );

        self::assertSame(422, $response->status, 'Article 26(6) is six months, enforced where it is written');
    }

    /* ── Routes ─────────────────────────────────────────────────────── */

    public function testTheRouteTableIsEmptyOnAFreeSiteAndTheBridgeMustBeRegistered(): void
    {
        $listed = $this->fixture->call('GET', '/manage/ai/routes', $this->fixture->manager());

        self::assertSame(200, $listed->status);
        self::assertNotNull($listed->data);
        self::assertSame(0, $listed->data['total']);

        $refused = $this->fixture->call(
            'POST',
            '/manage/ai/routes',
            $this->fixture->manager(),
            [
                'task_key' => 'check.schema_validity',
                'bridge_key' => 'nobody-ships-this',
                'max_cost_per_run' => 10,
            ],
            [],
            'route-1',
        );

        self::assertSame(422, $refused->status, 'a row may not name a package that is not installed');
    }

    public function testARouteIsCreatedDisabledAndAZeroCeilingRefusesEnabling(): void
    {
        $task = $this->fixture->kernel->registries->aiTasks->get('check.schema_validity');
        $this->fixture->env->translator->define($task->label(), 'Check question structure');
        $this->fixture->env->translator->define('ai.bridge.null.label', 'No model configured');
        $created = $this->fixture->call(
            'POST',
            '/manage/ai/routes',
            $this->fixture->manager(),
            ['task_key' => 'check.schema_validity', 'bridge_key' => 'null', 'max_cost_per_run' => 0],
            [],
            'route-1',
        );

        self::assertSame(201, $created->status);
        self::assertNotNull($created->data);
        self::assertFalse($created->data['enabled'], 'a provisioned route is inert until a person turns it on');
        self::assertTrue($created->data['bridge_available']);
        self::assertSame('Check question structure', $created->data['task_label']);
        self::assertSame('No model configured', $created->data['bridge_label']);

        $listed = $this->fixture->call('GET', '/manage/ai/routes', $this->fixture->manager());
        self::assertNotNull($listed->data);
        self::assertSame('Check question structure', $listed->data['items'][0]['task_label']);
        self::assertSame('No model configured', $listed->data['items'][0]['bridge_label']);

        $id = (int) $created->data['id'];

        $refused = $this->fixture->call(
            'PATCH',
            '/manage/ai/routes/' . $id,
            $this->fixture->manager(),
            ['enabled' => true],
            [],
            'enable-1',
        );

        self::assertSame(422, $refused->status, 'a ceiling whose default is off is not a ceiling');

        $enabled = $this->fixture->call(
            'PATCH',
            '/manage/ai/routes/' . $id,
            $this->fixture->manager(),
            ['enabled' => true, 'max_cost_per_run' => 1000],
            [],
            'enable-2',
        );

        self::assertSame(200, $enabled->status);
        self::assertNotNull($enabled->data);
        self::assertTrue($enabled->data['enabled']);
        self::assertSame('Check question structure', $enabled->data['task_label']);
        self::assertSame('No model configured', $enabled->data['bridge_label']);

        $deleted = $this->fixture->call(
            'DELETE',
            '/manage/ai/routes/' . $id,
            $this->fixture->manager(),
            [],
            [],
            'delete-1',
        );

        self::assertSame(200, $deleted->status);
        self::assertNotNull($deleted->data);
        self::assertTrue($deleted->data['deleted']);
    }

    public function testARouteWithAMissingTaskProviderRemainsReadable(): void
    {
        $created = $this->fixture->call(
            'POST',
            '/manage/ai/routes',
            $this->fixture->manager(),
            ['task_key' => 'vendor.removed_task', 'bridge_key' => 'null', 'max_cost_per_run' => 0],
            [],
            'route-missing-task',
        );

        self::assertSame(201, $created->status);
        self::assertNotNull($created->data);
        self::assertNull($created->data['task_label']);
        self::assertSame('vendor.removed_task', $created->data['task_key']);
    }

    public function testARouteWriteRefusesAFieldItDoesNotName(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/ai/routes',
            $this->fixture->manager(),
            ['task_key' => 'x', 'bridge_key' => 'null', 'max_cost_per_run' => 1, 'suggested_mark' => 5],
            [],
            'route-bad',
        );

        self::assertSame(422, $response->status);
    }

    /* ── Enablement and the stop ────────────────────────────────────── */

    public function testTheFourLevelsReadOffAndTheToggleListIsGeneratedFromTheRegistry(): void
    {
        $catalogue = require __DIR__ . '/../../../i18n/catalogue.php';

        foreach ($this->fixture->kernel->registries->aiPlacements->all() as $placement) {
            self::assertInstanceOf(DescribesAiPlacement::class, $placement);
            self::assertArrayHasKey($placement->description(), $catalogue);
            self::assertNotEmpty($catalogue[$placement->description()]);
            $this->fixture->env->translator->define($placement->description(), $catalogue[$placement->description()]);
        }

        $this->fixture->env->translator->define('ai.surface.instructor_workbench.label', 'Course authoring');
        $response = $this->fixture->call('GET', '/manage/ai/enablement', $this->fixture->manager());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        // ceilings joins them because every spend and rate ceiling used to be
        // unconfigurable: the settings resolver answered three keys and
        // returned the caller's own default for the rest, so all six were
        // pinned to their constants and the screen that lists them listed six
        // numbers nobody could change.
        self::assertSame(
            ['site_enabled', 'stop', 'levels', 'ceilings', 'placements', 'routes', 'policy'],
            array_keys($response->data),
        );
        self::assertSame(
            ['site', 'placement', 'route', 'person'],
            array_column($response->data['levels'], 'level'),
            'the levels are derived from the registered policies, not enumerated in a constant',
        );
        self::assertFalse($response->data['site_enabled']);
        self::assertSame(0, $response->data['routes']['enabled']);
        self::assertNull($response->data['policy']);

        foreach ($response->data['placements'] as $placement) {
            self::assertFalse($placement['enabled'], 'every placement is off by default');
            self::assertSame('free', $placement['edition']);
            self::assertSame($catalogue['ai.placement.' . $placement['key'] . '.description'], $placement['description']);

            if ($placement['surface_key'] === 'instructor_workbench') {
                self::assertSame('Course authoring', $placement['surface_label']);
            }
        }
    }

    public function testSettingTheSiteSwitchAndAPlacementSwitchInOneAct(): void
    {
        $before = $this->fixture->call('GET', '/manage/ai/enablement', $this->fixture->manager());
        self::assertNotNull($before->data);

        $keys = array_column($before->data['placements'], 'key');
        self::assertNotSame([], $keys, 'the free build ships placements');

        $saved = $this->fixture->call(
            'PUT',
            '/manage/ai/enablement',
            $this->fixture->manager(),
            ['site_enabled' => true, 'placements' => [$keys[0] => true]],
            [],
            'enable-site',
        );

        self::assertSame(200, $saved->status);
        self::assertNotNull($saved->data);
        self::assertTrue($saved->data['site_enabled']);

        $enabled = array_values(array_filter(
            $saved->data['placements'],
            static fn (array $row): bool => $row['enabled'] === true,
        ));

        self::assertCount(1, $enabled);
        self::assertSame($keys[0], $enabled[0]['key']);
    }

    public function testAPlacementKeyNoRegistryAnswersIsRefusedRatherThanStored(): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/manage/ai/enablement',
            $this->fixture->manager(),
            ['placements' => ['nobody.ships.this' => true]],
            [],
            'enable-ghost',
        );

        self::assertSame(422, $response->status);
    }

    public function testTheStopRecordsWhoSetItAndWhoClearedIt(): void
    {
        $set = $this->fixture->call(
            'POST',
            '/manage/ai/stop',
            $this->fixture->manager(),
            ['reason' => 'Reviewing the data-use statement.'],
            [],
            'stop-1',
        );

        self::assertSame(200, $set->status);
        self::assertNotNull($set->data);
        self::assertTrue($set->data['stopped']);
        self::assertSame(ManagementFixture::MANAGER_ID, $set->data['set_by']);
        self::assertSame('Reviewing the data-use statement.', $set->data['reason']);

        $placements = $this->fixture->call('GET', '/ai/placements', $this->fixture->manager());
        self::assertNotNull($placements->data);
        self::assertTrue($placements->data['stopped'], 'the stop is visible to every caller, not only to the admin');

        $cleared = $this->fixture->call('DELETE', '/manage/ai/stop', $this->fixture->manager(), [], [], 'stop-clear');

        self::assertSame(200, $cleared->status);
        self::assertNotNull($cleared->data);
        self::assertFalse($cleared->data['stopped']);
        self::assertSame(ManagementFixture::MANAGER_ID, $cleared->data['cleared_by']);
        self::assertNotNull($cleared->data['cleared_at']);
    }

    public function testTheStopAndTheEnablementRefuseATeacherAndAGuest(): void
    {
        self::assertSame(401, $this->fixture->call('GET', '/manage/ai/enablement', Principal::guest())->status);
        self::assertSame(403, $this->fixture->call('GET', '/manage/ai/enablement', $this->fixture->teacher())->status);
        self::assertSame(
            403,
            $this->fixture->call('POST', '/manage/ai/stop', $this->fixture->teacher(), [], [], 'k')->status,
        );
    }

    /* ── Runs, drafts and the redline ───────────────────────────────── */

    public function testTheRunLogCarriesEveryAttemptAndTheDryRunManifest(): void
    {
        $runId = $this->seedRun([
            'dry_run' => 1,
            'state' => 'previewed',
            'refusal_kind' => 'not_configured',
        ]);
        $this->seedAttempt($runId, 1, false, 'not_configured');

        $response = $this->fixture->call('GET', '/manage/ai/runs/' . $runId, $this->fixture->manager());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('previewed', $response->data['state']);
        self::assertTrue($response->data['dry_run']);
        self::assertSame('not_configured', $response->data['refusal']['kind']);
        self::assertCount(1, $response->data['attempts']);
        self::assertSame(
            ['lesson:88', 'submission:412'],
            $response->data['redaction_manifest'],
            'the dry run is the privacy artefact, and the manifest is what it renders',
        );
        self::assertArrayNotHasKey('score', $response->data);
        self::assertArrayNotHasKey('confidence', $response->data);
    }

    public function testTheAdminRunListFiltersOnStateAndOnTheDryRunFlag(): void
    {
        $this->seedRun(['dry_run' => 1, 'state' => 'previewed', 'run_key' => 'r-preview']);
        $this->seedRun(['dry_run' => 0, 'state' => 'refused', 'run_key' => 'r-refused']);

        $all = $this->fixture->call('GET', '/manage/ai/runs', $this->fixture->manager());
        self::assertNotNull($all->data);
        self::assertSame(2, $all->data['total'], 'absent means both, so a preview is not hidden');

        $refused = $this->fixture->call(
            'GET',
            '/manage/ai/runs',
            $this->fixture->manager(),
            [],
            ['state' => 'refused'],
        );
        self::assertNotNull($refused->data);
        self::assertSame(1, $refused->data['total']);
    }

    public function testACallerReadsTheirOwnRunAndNotSomebodyElses(): void
    {
        $mine = $this->seedRun(['principal_user_id' => ManagementFixture::TEACHER_ID, 'run_key' => 'mine']);
        $theirs = $this->seedRun(['principal_user_id' => ManagementFixture::OUTSIDER_ID, 'run_key' => 'theirs']);

        self::assertSame(200, $this->fixture->call('GET', '/ai/runs/' . $mine, $this->fixture->teacher())->status);
        self::assertSame(403, $this->fixture->call('GET', '/ai/runs/' . $theirs, $this->fixture->teacher())->status);
        self::assertSame(200, $this->fixture->call('GET', '/ai/runs/' . $theirs, $this->fixture->manager())->status);
    }

    public function testADraftIsNeverReachableByALearnerReadPath(): void
    {
        $runId = $this->seedRun([]);
        $draftId = $this->seedDraft($runId);

        self::assertSame(
            403,
            $this->fixture->call(
                'GET',
                '/ai/drafts',
                $this->fixture->learner(),
                [],
                ['course_id' => (string) $this->fixture->courseId],
            )->status,
        );
        self::assertSame(403, $this->fixture->call('GET', '/ai/drafts/' . $draftId, $this->fixture->learner())->status);
        self::assertSame(401, $this->fixture->call('GET', '/ai/drafts/' . $draftId, Principal::guest())->status);
    }

    public function testTheReviewQueueCarriesTheFindingsAndTheAcceptorMiss(): void
    {
        $runId = $this->seedRun([]);
        $draftId = $this->seedDraft($runId);
        $this->seedFinding($draftId);

        self::assertSame(
            403,
            $this->fixture->call('GET', '/ai/drafts', $this->fixture->teacher())->status,
            'an unscoped queue is a site-wide read, so a teacher names the course they teach',
        );

        $listed = $this->fixture->call(
            'GET',
            '/ai/drafts',
            $this->fixture->teacher(),
            [],
            ['course_id' => (string) $this->fixture->courseId],
        );

        self::assertSame(200, $listed->status);
        self::assertNotNull($listed->data);
        self::assertSame(1, $listed->data['total']);
        self::assertCount(1, $listed->data['items'][0]['findings']);
        self::assertSame('blocking', $listed->data['items'][0]['findings'][0]['severity']);

        $one = $this->fixture->call('GET', '/ai/drafts/' . $draftId, $this->fixture->teacher());
        self::assertNotNull($one->data);
        self::assertTrue(
            $one->data['acceptor_available'],
            'the free build registers an acceptor for the question kind',
        );
        self::assertNotNull($one->data['run'], 'the provenance panel of the review moment');

        $orphan = $this->seedDraft($runId, 'nobody.ships.this');
        $missing = $this->fixture->call('GET', '/ai/drafts/' . $orphan, $this->fixture->teacher());
        self::assertNotNull($missing->data);
        self::assertFalse(
            $missing->data['acceptor_available'],
            'no acceptor answers that kind, so nothing is written and the screen says so',
        );
    }

    public function testAnEditLandsBesideThePayloadAndNeverOverThem(): void
    {
        $runId = $this->seedRun([]);
        $draftId = $this->seedDraft($runId);

        $edited = $this->fixture->call(
            'PATCH',
            '/ai/drafts/' . $draftId,
            $this->fixture->teacher(),
            ['edited_payload' => ['stem' => 'The reviewer rewrote this.']],
            [],
            'edit-1',
        );

        self::assertSame(200, $edited->status);
        self::assertNotNull($edited->data);
        self::assertSame(['stem' => 'Original stem.'], $edited->data['payload'], 'the edit distance survives');
        self::assertSame(['stem' => 'The reviewer rewrote this.'], $edited->data['edited_payload']);
        self::assertSame('in_review', $edited->data['state']);
        self::assertSame(ManagementFixture::TEACHER_ID, $edited->data['reviewer_user_id']);
    }

    public function testRejectingKeepsTheCodeAndReturnsTheDraftToGenerated(): void
    {
        $runId = $this->seedRun([]);
        $draftId = $this->seedDraft($runId);

        $rejected = $this->fixture->call(
            'POST',
            '/ai/drafts/' . $draftId . '/reject',
            $this->fixture->teacher(),
            ['reason_code' => 'distractor_implausible'],
            [],
            'reject-1',
        );

        self::assertSame(200, $rejected->status);
        self::assertNotNull($rejected->data);
        self::assertSame('generated', $rejected->data['state']);
        self::assertSame('distractor_implausible', $rejected->data['reject_reason_code']);
    }

    public function testDiscardingKeepsTheRunBecauseADiscardedRunStillCostMoney(): void
    {
        $runId = $this->seedRun([]);
        $draftId = $this->seedDraft($runId);

        $discarded = $this->fixture->call(
            'POST',
            '/ai/drafts/' . $draftId . '/discard',
            $this->fixture->teacher(),
            [],
            [],
            'discard-1',
        );

        self::assertSame(200, $discarded->status);
        self::assertNotNull($discarded->data);
        self::assertSame('discarded', $discarded->data['state']);

        self::assertSame(
            200,
            $this->fixture->call('GET', '/manage/ai/runs/' . $runId, $this->fixture->manager())->status,
            'the run row survives the discard',
        );

        $again = $this->fixture->call(
            'POST',
            '/ai/drafts/' . $draftId . '/discard',
            $this->fixture->teacher(),
            [],
            [],
            'discard-2',
        );

        self::assertSame(409, $again->status, 'a decided draft cannot be decided about twice');
    }

    /* ── Provenance, recall and the usage report ────────────────────── */

    public function testProvenanceAndRecallReadTheRowThatOutlivesTheDraft(): void
    {
        $runId = $this->seedRun([]);
        $draftId = $this->seedDraft($runId);
        $this->seedProvenance($runId, $draftId);

        $provenance = $this->fixture->call(
            'GET',
            '/ai/provenance/question/4242',
            $this->fixture->manager(),
        );

        self::assertSame(200, $provenance->status);
        self::assertNotNull($provenance->data);
        self::assertCount(1, $provenance->data['items']);
        self::assertSame('human-led-genai-enhanced', $provenance->data['items'][0]['disclosure_tier']);
        self::assertSame(ManagementFixture::TEACHER_ID, $provenance->data['items'][0]['accepted_by']);

        $preview = $this->fixture->call(
            'POST',
            '/manage/ai/runs/' . $runId . '/recall',
            $this->fixture->manager(),
            [],
            [],
            'recall-1',
        );

        self::assertSame(200, $preview->status);
        self::assertNotNull($preview->data);
        self::assertCount(1, $preview->data['artefacts']);
        self::assertSame(['question' => 1], $preview->data['by_type']);
        self::assertFalse($preview->data['artefacts'][0]['superseded'], 'a preview changes nothing');
        self::assertArrayNotHasKey('superseded', $preview->data, 'and reports no count, because it stamped none');

        $confirmed = $this->fixture->call(
            'POST',
            '/manage/ai/runs/' . $runId . '/recall',
            $this->fixture->manager(),
            ['confirmation' => RecallAiRun::CONFIRMATION, 'reason' => 'The source lesson was withdrawn.'],
            [],
            'recall-2',
        );

        self::assertSame(200, $confirmed->status);
        self::assertNotNull($confirmed->data);
        self::assertSame(1, $confirmed->data['superseded']);

        $after = $this->fixture->call(
            'GET',
            '/ai/provenance/question/4242',
            $this->fixture->manager(),
        );
        self::assertNotNull($after->data);
        self::assertNotNull(
            $after->data['items'][0]['superseded_at'],
            'the row is stamped and left in place, never removed',
        );
    }

    public function testTheUsageReportRunsThroughTheReportRegistryAndNeverSumsTwoUnits(): void
    {
        $this->seedRun(['state' => 'succeeded', 'cost_unit' => 'tokens', 'cost_charged' => 120, 'run_key' => 'a']);
        $this->seedRun(['state' => 'succeeded', 'cost_unit' => 'usd_micros', 'cost_charged' => 40, 'run_key' => 'b']);
        $this->seedRun(['state' => 'refused', 'refusal_kind' => 'ceiling_exceeded', 'run_key' => 'c']);

        $response = $this->fixture->call('GET', '/manage/ai/usage', $this->fixture->manager());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);

        $units = array_column($response->data['totals'], 'cost_unit');
        sort($units);
        self::assertSame(['none', 'tokens', 'usd_micros'], $units, 'one row per unit, never one wrong sum');

        $refusals = array_column($response->data['refusals'], 'count', 'kind');
        self::assertSame(1, $refusals['ceiling_exceeded']);
        self::assertFalse($response->data['unmetered']);
        self::assertNotSame([], $response->data['ceilings'], 'the ceilings come from the policy registry');
    }

    public function testTheUsageReportRefusesATeacher(): void
    {
        self::assertSame(403, $this->fixture->call('GET', '/manage/ai/usage', $this->fixture->teacher())->status);
        self::assertSame(401, $this->fixture->call('GET', '/manage/ai/usage', Principal::guest())->status);
    }

    /* ── The learner's right to know ────────────────────────────────── */

    public function testTheActivityViewReadsTheSubjectRowsAndNeverADraftPayload(): void
    {
        $runId = $this->seedRun(['principal_user_id' => ManagementFixture::TEACHER_ID, 'state' => 'succeeded']);
        $this->seedDraft($runId);
        $this->seedSubject($runId, ManagementFixture::LEARNER_ID, 'subject');

        $response = $this->fixture->call('GET', '/me/ai-activity', $this->fixture->learner());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(1, $response->data['total']);

        self::assertNotSame('', $response->data['as_of'], 'a purge runs daily, so the answer is stamped');

        $entry = $response->data['items'][0];
        self::assertSame($runId, $entry['run_id']);
        self::assertSame('subject', $entry['my_role']);
        self::assertSame(ManagementFixture::TEACHER_ID, $entry['invoked_by']);
        self::assertSame('Tara Teacher', $entry['invoked_by_name']);
        self::assertNull($entry['model'], 'the free build ran no model at all');
        self::assertSame('null', $entry['bridge_key'], 'the free bridge is a real provider key');
        self::assertSame(
            ['lesson:88', 'submission:412'],
            $entry['fields_sent'],
            'from the manifest, never a category description',
        );
        self::assertArrayNotHasKey('payload', $entry);
        self::assertArrayNotHasKey('score', $entry);
        self::assertArrayNotHasKey('confidence', $entry);
    }

    public function testTheActivityViewAnswersForTheCallerAndForNobodyElse(): void
    {
        $runId = $this->seedRun([]);
        $this->seedSubject($runId, ManagementFixture::LEARNER_ID, 'subject');

        $other = $this->fixture->call('GET', '/me/ai-activity', $this->fixture->outsider());

        self::assertSame(200, $other->status);
        self::assertNotNull($other->data);
        self::assertSame(0, $other->data['total'], 'a run about somebody else is not in my activity');

        self::assertSame(401, $this->fixture->call('GET', '/me/ai-activity', Principal::guest())->status);
    }

    /* ── Fixtures ───────────────────────────────────────────────────── */

    private function createPolicy(string $title = 'How this site uses AI', string $key = 'policy-1'): ApiResponse
    {
        return $this->fixture->call(
            'POST',
            '/manage/ai/policies',
            $this->fixture->manager(),
            [
                'title' => $title,
                // The eight-part document rather than prose. A version that has
                // said nothing for a part cannot be activated, because
                // activating it records every person's acceptance of a
                // document that discloses nothing.
                'body' => self::policyParts(),
                'retention_days' => 400,
                'redaction_level' => 'strict',
                'enabled_tasks' => ['check.schema_validity'],
            ],
            [],
            $key,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function policyParts(): array
    {
        return [
            'based_on_external_models' => 'This feature is based on external large language models.',
            'accuracy_varies_check_outputs' => 'Accuracy varies. Check what it produces before you use it.',
            'handled_under_provider_policy' => 'What is sent is handled under the provider policy this route names.',
            'interactions_may_be_recorded' => 'A record of interactions is saved on this site.',
            'enabled_tasks' => 'Only the tasks listed on the AI screen are enabled here.',
            'route_and_model_family' => 'The route and model family in force are shown beside each task.',
            'fields_sent_and_never_sent' => 'The redaction manifest lists exactly which fields are sent.',
            'retention_and_where_to_ask' => 'Records are kept for the stated window; ask the site administrator.',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function seedRun(array $overrides): int
    {
        return $this->fixture->env->ports->database->insert('ai_runs', array_merge([
            'run_key' => 'run-' . bin2hex(random_bytes(4)),
            'task_key' => 'check.schema_validity',
            'task_version' => 1,
            'placement_key' => 'question_bank',
            'bridge_key' => 'null',
            'policy_version' => 0,
            'principal_user_id' => ManagementFixture::TEACHER_ID,
            'actor_kind' => 'person',
            'course_id' => $this->fixture->courseId,
            'locale' => 'en-GB',
            'inputs_digest' => str_repeat('a', 64),
            'redaction_manifest' => json_encode(
                ['_v' => 1, 'fields' => ['lesson:88', 'submission:412']],
                \JSON_THROW_ON_ERROR,
            ),
            'redaction_manifest_version' => 1,
            'dry_run' => 0,
            'state' => 'reserved',
            'cost_unit' => 'none',
            'retain_until' => '2027-01-01 00:00:00',
            'created_at' => '2026-09-08 09:00:00',
            'updated_at' => '2026-09-08 09:00:00',
        ], $overrides));
    }

    private function seedAttempt(int $runId, int $attemptNo, bool $success, string $refusalKind): void
    {
        $this->fixture->env->ports->database->insert('ai_run_attempts', [
            'run_id' => $runId,
            'attempt_no' => $attemptNo,
            'bridge_key' => 'null',
            'success' => $success ? 1 : 0,
            'refusal_kind' => $refusalKind,
            'cost_unit' => 'none',
            'started_at' => '2026-09-08 09:00:00',
        ]);
    }

    private function seedDraft(int $runId, string $kind = 'question'): int
    {
        return $this->fixture->env->ports->database->insert('ai_drafts', [
            'run_id' => $runId,
            'draft_kind' => $kind,
            'target_extension_point' => 'QuestionType',
            'target_provider_key' => 'multiple_choice',
            'course_id' => $this->fixture->courseId,
            'ordinal' => 0,
            'payload' => json_encode(['_v' => 1, 'stem' => 'Original stem.'], \JSON_THROW_ON_ERROR),
            'payload_version' => 1,
            'state' => 'generated',
            'findings_blocking' => 1,
            'findings_advisory' => 0,
            'retain_until' => '2026-12-08 00:00:00',
            'created_at' => '2026-09-08 09:00:00',
            'updated_at' => '2026-09-08 09:00:00',
        ]);
    }

    private function seedFinding(int $draftId): void
    {
        $this->fixture->env->ports->database->insert('ai_draft_findings', [
            'draft_id' => $draftId,
            'check_key' => 'check.item_writing_flaws',
            'severity' => 'blocking',
            'code' => 'all_of_the_above',
            'message' => 'The option list contains "All of the above".',
            'evidence_version' => 1,
            'acknowledged' => 0,
            'created_at' => '2026-09-08 09:00:00',
            'updated_at' => '2026-09-08 09:00:00',
        ]);
    }

    private function seedProvenance(int $runId, int $draftId): void
    {
        $this->fixture->env->ports->database->insert('ai_artefact_provenance', [
            'subject_type' => 'question',
            'subject_id' => 4242,
            'run_id' => $runId,
            'draft_id' => $draftId,
            'task_key' => 'check.schema_validity',
            'task_version' => 1,
            'inputs_digest' => str_repeat('a', 64),
            'disclosure_tier' => 'human-led-genai-enhanced',
            'edit_distance_ratio' => '0.1200',
            'human_edited' => 1,
            'accepted_by' => ManagementFixture::TEACHER_ID,
            'accepted_at' => '2026-09-08 10:00:00',
        ]);
    }

    private function seedSubject(int $runId, int $userId, string $role): void
    {
        $this->fixture->env->ports->database->insert('ai_run_subjects', [
            'run_id' => $runId,
            'user_id' => $userId,
            'role' => $role,
        ]);
    }
}
