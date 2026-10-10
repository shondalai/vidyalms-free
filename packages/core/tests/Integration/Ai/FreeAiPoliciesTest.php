<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Ai\AiDataUsePolicyContent;
use Shondalai\VidyaLMS\Core\Application\Ai\AiEnablement;
use Shondalai\VidyaLMS\Core\Provider\Ai\Placement\CoreAiPlacement;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\PerRunCallsPolicy;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\PlacementEnabledPolicy;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\PolicyAcceptedPolicy;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\WindowedCeilingPolicy;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The eleven free ceilings and gates, exercised over the tables they read.
 *
 * All eleven ship free, and the reason is one sentence: a ceiling that exists
 * only in the paid build is not a ceiling. A bring-your-own-key route meters
 * nothing, so the two unit-free call counts are what bound it, and both of
 * those are here rather than in a package a site has to buy.
 *
 * The two behaviours worth separating are veto and defer. A deny is terminal;
 * a defer is not, and a period ceiling reached has a real answer a deny-set
 * cannot express: not now, at the window boundary.
 */
final class FreeAiPoliciesTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testTheTwelveFreePoliciesAreRegisteredUnderTheirPublishedKeys(): void
    {
        $expected = [
            'attempt_state', 'content_scope', 'per_principal_preview_rate', 'per_principal_rate',
            'per_run_calls', 'per_run_cost', 'per_scope_calls', 'per_scope_spend', 'placement_enabled',
            'policy_accepted', 'route_available',
            // The twelfth binds the subject to the scope. content_scope asks
            // whether this person may open the course the run NAMES; this one
            // asks whether the row it POINTS AT is in that course.
            'subject_scope',
        ];

        $registered = $this->fixture->kernel->registries->aiPolicies->keys();
        sort($registered);

        self::assertSame($expected, $registered);
    }

    public function testEveryPolicyVetoesOrDefersAndNoneOfThemSelects(): void
    {
        // A policy that could select would have to know the route catalogue,
        // which is exactly the coupling the seam exists to avoid. The shape
        // of the decision object is the enforcement: it carries a denied map
        // and a deferred map and nothing else.
        $decision = new \ReflectionClass(PolicyDecision::class);

        self::assertSame(
            ['denied', 'deferred'],
            array_map(
                static fn (\ReflectionProperty $property): string => $property->getName(),
                $decision->getProperties(\ReflectionProperty::IS_PUBLIC),
            ),
        );
    }

    public function testTheStopControlBeatsEveryOtherLevel(): void
    {
        $decision = $this->decide(new PlacementEnabledPolicy(), [
            PlacementEnabledPolicy::SETTING_STOP => true,
            PlacementEnabledPolicy::SETTING_SITE_ENABLED => true,
            AiEnablement::placementSetting(CoreAiPlacement::WORKBENCH_CURRICULUM) => true,
        ]);

        self::assertSame(
            PlacementEnabledPolicy::REASON_STOPPED,
            $decision->denied[PolicyDecision::TARGET_AI_RUN] ?? null,
            'a stop that a placement toggle could override is not a stop',
        );
    }

    public function testEverythingIsOffByDefaultAtEveryLevel(): void
    {
        $decision = $this->decide(new PlacementEnabledPolicy(), []);

        self::assertSame(
            PlacementEnabledPolicy::REASON_SITE_DISABLED,
            $decision->denied[PolicyDecision::TARGET_AI_RUN] ?? null,
        );

        $placementOff = $this->decide(new PlacementEnabledPolicy(), [
            PlacementEnabledPolicy::SETTING_SITE_ENABLED => true,
        ]);

        self::assertSame(
            PlacementEnabledPolicy::REASON_PLACEMENT_DISABLED,
            $placementOff->denied[PolicyDecision::TARGET_AI_RUN] ?? null,
        );
    }

    public function testASiteWithNoPublishedPolicyRefusesEveryRun(): void
    {
        $decision = $this->decide(new PolicyAcceptedPolicy($this->fixture->env->database), []);

        self::assertSame(
            PolicyAcceptedPolicy::REASON_NO_POLICY,
            $decision->denied[PolicyDecision::TARGET_AI_RUN] ?? null,
            'a run under no policy at all is what the version column exists to make impossible',
        );
    }

    public function testAPersonWhoAcceptedAnOlderVersionIsToldItWasSuperseded(): void
    {
        $database = $this->fixture->env->database;
        $database->insert('ai_policies', [
            'version' => 1,
            'title' => 'v1',
            'body' => json_encode(AiDataUsePolicyContent::skeleton()),
            'locale' => 'en-GB',
            'redaction_level' => 'strict',
            'retention_days' => 90,
            'training_reuse' => 0,
            'enabled_tasks' => '[]',
            'enabled_tasks_version' => 1,
            'body_hash' => str_repeat('a', 64),
            'effective_from' => '2026-01-01 00:00:00',
            'state' => 'superseded',
            'created_by' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $database->insert('ai_policies', [
            'version' => 2,
            'title' => 'v2',
            'body' => json_encode(AiDataUsePolicyContent::skeleton()),
            'locale' => 'en-GB',
            'redaction_level' => 'strict',
            'retention_days' => 90,
            'training_reuse' => 0,
            'enabled_tasks' => '[]',
            'enabled_tasks_version' => 1,
            'body_hash' => str_repeat('b', 64),
            'effective_from' => '2026-02-01 00:00:00',
            'state' => 'active',
            'created_by' => 1,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $database->insert('ai_policy_acceptances', [
            'user_id' => ManagementFixture::TEACHER_ID,
            'policy_version' => 1,
            'scope_type' => 'site',
            'scope_id' => 0,
            'placement_key' => null,
            'accepted_at' => '2026-01-02 00:00:00',
            'evidence' => '{}',
            'evidence_version' => 1,
            'created_at' => '2026-01-02 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ]);

        $policy = new PolicyAcceptedPolicy($database);

        self::assertSame(
            PolicyAcceptedPolicy::REASON_SUPERSEDED,
            $this->decide($policy, [])->denied[PolicyDecision::TARGET_AI_RUN] ?? null,
        );

        $database->insert('ai_policy_acceptances', [
            'user_id' => ManagementFixture::TEACHER_ID,
            'policy_version' => 2,
            'scope_type' => 'site',
            'scope_id' => 0,
            'placement_key' => null,
            'accepted_at' => '2026-02-02 00:00:00',
            'evidence' => '{}',
            'evidence_version' => 1,
            'created_at' => '2026-01-02 00:00:00',
            'updated_at' => '2026-01-02 00:00:00',
        ]);

        self::assertSame([], $this->decide($policy, [])->denied);
    }

    public function testAWindowedCeilingDefersRatherThanDenying(): void
    {
        $policy = WindowedCeilingPolicy::perPrincipalRate($this->fixture->env->database);

        $decision = $this->decide($policy, ['ceiling.per_principal_rate' => 0]);

        self::assertSame([], $decision->denied, 'a period ceiling is not terminal');
        self::assertArrayHasKey(PolicyDecision::TARGET_AI_RUN, $decision->deferred);
        self::assertGreaterThan(
            $this->now(),
            $decision->deferred[PolicyDecision::TARGET_AI_RUN],
            'a defer says when, or it is a deny with better manners',
        );
    }

    public function testThePreviewBudgetIsSeparateFromTheRealOne(): void
    {
        // A ceiling that counted previews would charge a site owner for
        // exercising the privacy control the design sells: ten refusals for
        // an unaccepted policy version would rate-limit somebody for a
        // condition they never caused.
        $live = WindowedCeilingPolicy::perPrincipalRate($this->fixture->env->database);
        $preview = WindowedCeilingPolicy::perPrincipalPreviewRate($this->fixture->env->database);

        self::assertNotSame($live->key(), $preview->key());
        self::assertSame(['previewed'], WindowedCeilingPolicy::PREVIEW_STATES);
        self::assertNotContains('previewed', WindowedCeilingPolicy::LIVE_STATES);
        self::assertNotContains('refused', WindowedCeilingPolicy::LIVE_STATES);
        self::assertNotContains('cancelled', WindowedCeilingPolicy::LIVE_STATES);
        self::assertContains('reserved', WindowedCeilingPolicy::LIVE_STATES);
        self::assertContains('running', WindowedCeilingPolicy::LIVE_STATES);
    }

    public function testTheUnitFreeCallCeilingBoundsANonMeteringRoute(): void
    {
        $decision = $this->decide(
            new PerRunCallsPolicy(),
            [PerRunCallsPolicy::SETTING => 3],
            AiRunIntent::forTask('question.template_aig', 'course', 1, null, 40),
        );

        self::assertSame(
            PerRunCallsPolicy::REASON_TOO_MANY_CALLS,
            $decision->denied[PolicyDecision::TARGET_AI_RUN] ?? null,
        );
    }

    public function testARouteThatServesNothingRefusesWithATypedReasonAndNeverThrows(): void
    {
        $policy = $this->fixture->kernel->registries->aiPolicies->get('route_available');
        $decision = $this->decide($policy, []);

        self::assertSame(
            'ai.policy.no_route',
            $decision->denied[PolicyDecision::TARGET_AI_RUN] ?? null,
            'a free site has no enabled route, and that answers not_configured rather than failing',
        );
    }

    public function testTheFourLevelsAndTheStopArePublishedAsData(): void
    {
        // Derived from the registered policies rather than enumerated, so a
        // vendor gate publishes its own row and the screen stops showing four
        // green levels while a fifth one refuses every run.
        $levels = AiEnablement::levels($this->fixture->kernel->registries->aiPolicies);

        self::assertCount(4, $levels);
        self::assertSame(
            ['site', 'placement', 'route', 'person'],
            array_column($levels, 'level'),
            'the order is the order a run is refused in, so a person is told the outermost reason first',
        );

        $stop = AiEnablement::stop();

        self::assertSame('site', $stop['scope']);
        self::assertSame(AiEnablement::REGISTRY_KEY, $stop['registry_key']);
        self::assertSame(AiEnablement::STOP_PROVIDER_KEY, $stop['provider_key']);
    }

    public function testTheDataUsePolicyCarriesTheFourPartsOnlyThisDesignCanSupply(): void
    {
        self::assertCount(8, AiDataUsePolicyContent::parts());
        self::assertContains('enabled_tasks', AiDataUsePolicyContent::DERIVED_PARTS);
        self::assertContains('route_and_model_family', AiDataUsePolicyContent::DERIVED_PARTS);
        self::assertContains('fields_sent_and_never_sent', AiDataUsePolicyContent::DERIVED_PARTS);
        self::assertContains('retention_and_where_to_ask', AiDataUsePolicyContent::DERIVED_PARTS);

        // The field the consent register this is measured against lacks, and
        // the whole difference between a consent record and a consent claim.
        self::assertSame('version', AiDataUsePolicyContent::VERSION_FIELD);
        self::assertSame(AiDataUsePolicyContent::parts(), AiDataUsePolicyContent::missingParts([]));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function decide(
        AiPolicyInterface $policy,
        array $settings,
        ?AiRunIntent $intent = null,
    ): PolicyDecision {
        return $policy->decide(
            $intent ?? AiRunIntent::forTask(
                'check.schema_validity',
                'course',
                $this->fixture->courseId,
                CoreAiPlacement::WORKBENCH_CURRICULUM,
            ),
            AiDecisionContext::create(
                $this->now(),
                $this->fixture->teacher(),
                'course',
                $this->fixture->courseId,
                null,
                1,
                static fn (string $key, mixed $default = null): mixed => $settings[$key] ?? $default,
            ),
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-05-01 09:00:00');
    }
}
