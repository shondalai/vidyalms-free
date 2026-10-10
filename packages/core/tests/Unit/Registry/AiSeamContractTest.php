<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Registry;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftAcceptorInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiFactProviderInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPlacementInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRecipeInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRedactorInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiSurfaceInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\AutomationActionInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPolicyInterface;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;
use Shondalai\VidyaLMS\Core\Tests\Support\Ai\BridgeCallingTask;

/**
 * The eleven AI and automation seams, pinned at their designed signatures.
 *
 * Widening a shipped provider-facing interface is forbidden the moment a
 * provider implements it, and the wave that widened these eleven was the last
 * moment at which all of them were verifiably unimplemented. The first two
 * cases carry that history and are asserted on a BOOTED kernel rather than on
 * a bare Registries, which default-constructs every registry and would answer
 * the empty list by construction for ever.
 *
 * Both are now counts rather than emptiness assertions, and the change of
 * direction is the record that the licence was spent rather than lost: all
 * eleven seams ship free providers, so a change to any of these interfaces
 * now breaks working free code, which is exactly the fact these two cases
 * exist to state.
 *
 * The eleven reflection tests are the closure. After this wave a seam
 * interface never changes again: new behaviour rides a capability interface
 * probed with instanceof, exactly as it does on the availability conditions
 * and on the question types.
 */
final class AiSeamContractTest extends TestCase
{
    private const AI = 'Shondalai\\VidyaLMS\\Core\\Registry\\Ai\\';
    private const AUTOMATION = 'Shondalai\\VidyaLMS\\Core\\Registry\\Automation\\';
    private const CONTEXT = 'Shondalai\\VidyaLMS\\Core\\Registry\\Context\\';

    /**
     * The exit gate: every one of the eight AI seams has at least one free
     * provider, on a BOOTED kernel.
     *
     * This case is the successor of the emptiness assertion that stood here
     * while the seams were being widened, and the succession is the point. An
     * earlier form did `new Registries()` and asserted the empty list, which
     * Registries default-constructs every registry to: it was true by
     * construction for ever and could never fail. Booted, it became a real
     * tripwire whose whole job was to fail the moment a provider registered,
     * so the widening could not quietly become unsafe.
     *
     * That licence is now spent, deliberately and once. The interfaces are
     * closed, and what replaces the tripwire is the rule the standards page
     * publishes for every other registry in the product: **every extension
     * point ships at least one working free provider and a free code path
     * that reaches it.** The Registries docblock recorded these eight as the
     * one place that rule was deferred rather than met; this is where the
     * deferral ends, and a seam losing its last free provider fails here.
     */
    public function testEveryAiSeamHasAFreeProviderOnABootedKernel(): void
    {
        $registries = ManagementFixture::create()->kernel->registries;

        foreach (
            [
                'aiRecipes' => $registries->aiRecipes,
                'aiPlacements' => $registries->aiPlacements,
                'aiSurfaces' => $registries->aiSurfaces,
                'modelBridges' => $registries->modelBridges,
                'aiPolicies' => $registries->aiPolicies,
                'aiRedactors' => $registries->aiRedactors,
                'aiFactProviders' => $registries->aiFactProviders,
                'aiDraftAcceptors' => $registries->aiDraftAcceptors,
            ] as $property => $registry
        ) {
            self::assertNotSame(
                [],
                $registry->keys(),
                $property . ' ships no free provider, so the seam has nothing a free site can reach',
            );
        }

        // The counts the design names, so a provider quietly disappearing is
        // a failure rather than a silently smaller catalogue.
        self::assertCount(1, $registries->aiRecipes->keys());
        self::assertCount(9, $registries->aiPlacements->keys());
        self::assertCount(6, $registries->aiSurfaces->keys());
        self::assertCount(1, $registries->modelBridges->keys());
        self::assertCount(12, $registries->aiPolicies->keys());
        self::assertCount(1, $registries->aiRedactors->keys());
        self::assertCount(18, $registries->aiFactProviders->keys());
        self::assertCount(6, $registries->aiDraftAcceptors->keys());
        self::assertCount(11, $registries->aiTasks->keys());
    }

    /**
     * The other half of the rule, and the half that was never asserted.
     *
     * The standards page states it as "every extension point ships at least
     * one working free provider AND a free code path that reaches it", and
     * the case above asserts only the first clause. For `modelBridges` the
     * second was false: nothing seated an `AiBridgeCaller` on the task
     * context, so `ModelBridgeInterface::run()` was reached by two unit tests
     * that built their collaborators by hand and by no shipped code path at
     * all, and the run row's `bridge_key` was a literal string that would have
     * read `null` on a site whose bridge registry was empty.
     *
     * So each seam is exercised THROUGH a shipped use case here, not through
     * the registry.
     */
    public function testEveryAiSeamsFreeProviderIsReachedByAShippedCodePath(): void
    {
        $fixture = ManagementFixture::create();
        $registries = $fixture->kernel->registries;
        $database = $fixture->env->database;

        // aiPlacements and aiSurfaces: the discovery route resolves a surface
        // for every placement it offers and renders the placement's own
        // disclosure.
        $placements = $fixture->call('GET', '/ai/placements', $fixture->manager());
        self::assertSame(200, $placements->status);
        self::assertNotNull($placements->data);
        self::assertNotSame([], $placements->data['items'], 'no placement reached the discovery route');

        // aiPolicies: a default site refuses, and the refusal names a shipped
        // policy's own reason rather than a generic denial.
        $refused = $fixture->call('POST', '/ai/runs', $fixture->manager(), [
            'task_key' => 'check.schema_validity',
            'subject_type' => 'course',
            'subject_id' => $fixture->courseId,
            'course_id' => $fixture->courseId,
        ], [], 'seam-refused');
        self::assertSame(200, $refused->status);
        self::assertNotNull($refused->data);
        self::assertSame('refused', $refused->data['state']);
        self::assertSame(
            'ai.policy.none_published',
            $refused->data['error_message'],
            'the policy chain refused, and the run row names which gate did it',
        );

        self::enableAi($fixture, 'test.calls_the_bridge');

        // modelBridges, aiRedactors and aiFactProviders in one run: a task
        // that asks for a call reaches the null bridge through the caller the
        // pipeline seats, the strict redactor is what built what it sent, and
        // the attempt row names the bridge that answered.
        $task = new BridgeCallingTask();
        $registries->aiTasks->register($task);

        $run = $fixture->call('POST', '/ai/runs', $fixture->teacher(), [
            'task_key' => 'test.calls_the_bridge',
            'subject_type' => 'course',
            'subject_id' => $fixture->courseId,
            'course_id' => $fixture->courseId,
            'scope_type' => 'course',
            'scope_id' => $fixture->courseId,
        ], [], 'seam-bridge');

        self::assertSame(200, $run->status, json_encode($run->data, JSON_THROW_ON_ERROR));
        self::assertSame(1, $task->calls);
        self::assertTrue($task->sawBridge, 'the pipeline seats a bridge caller on the task context');
        self::assertNotNull($task->answer, 'the task asked for a call and got an answer');
        self::assertNotNull($run->data);
        self::assertSame('null', $run->data['bridge_key'], 'and the run names the bridge the route selected');
        self::assertNotNull($run->data['route_id'], 'the route the run selected is on the row');

        $attempts = $database->selectAll(
            \Shondalai\VidyaLMS\Core\Port\Database\Query::from('ai_run_attempts')
                ->select(['bridge_key'])
                ->where('run_id', '=', (int) $run->data['id']),
        );
        self::assertSame(
            [['bridge_key' => 'null']],
            $attempts,
            'the attempt ledger names the bridge that answered, written by the shipped caller',
        );

        // aiRecipes: the queue resolves the shipped recipe by key.
        $job = $fixture->call('POST', '/ai/jobs', $fixture->teacher(), [
            'recipe_key' => 'outline.structural_build',
            'scope_type' => 'course',
            'scope_id' => $fixture->courseId,
        ], [], 'seam-recipe');
        self::assertSame(202, $job->status, json_encode($job->data, JSON_THROW_ON_ERROR));

        // aiDraftAcceptors: the accept use case resolves an acceptor by draft
        // kind, which is the only path from a draft to an artefact.
        self::assertNotSame([], $registries->aiDraftAcceptors->keys());
    }

    /**
     * The four levels an administrator turns on, as the administrator turns
     * them on.
     */
    private static function enableAi(ManagementFixture $fixture, string $taskKey): void
    {
        $placements = [];

        foreach ($fixture->kernel->registries->aiPlacements->keys() as $key) {
            $placements[$key] = true;
        }

        $fixture->call('PUT', '/manage/ai/enablement', $fixture->manager(), [
            'site_enabled' => true,
            'placements' => $placements,
        ], [], 'seam-enable');

        $policy = $fixture->call('POST', '/manage/ai/policies', $fixture->manager(), [
            'title' => 'How this site uses AI',
            'body' => [
                'based_on_external_models' => 'a',
                'accuracy_varies_check_outputs' => 'b',
                'handled_under_provider_policy' => 'c',
                'interactions_may_be_recorded' => 'd',
                'enabled_tasks' => 'e',
                'route_and_model_family' => 'f',
                'fields_sent_and_never_sent' => 'g',
                'retention_and_where_to_ask' => 'h',
            ],
            'retention_days' => 400,
        ], [], 'seam-policy');
        $version = (int) ($policy->data['version'] ?? 1);
        $fixture->call('PATCH', '/manage/ai/policies/' . $version, $fixture->manager(), [
            'state' => 'active',
        ], [], 'seam-activate');
        $fixture->call('PUT', '/ai/policy/acceptance', $fixture->teacher(), [
            'version' => $version,
        ], [], 'seam-accept');

        $route = $fixture->call('POST', '/manage/ai/routes', $fixture->manager(), [
            'task_key' => $taskKey,
            'bridge_key' => 'null',
            'max_cost_per_run' => 100,
            'scope_type' => 'site',
        ], [], 'seam-route');
        $fixture->call('PATCH', '/manage/ai/routes/' . (int) ($route->data['id'] ?? 0), $fixture->manager(), [
            'enabled' => true,
        ], [], 'seam-route-on');
    }

    /**
     * The three automation seams are knowingly populated, and their interfaces
     * are therefore closed from here.
     *
     * Counted rather than asserted empty, because the free build registers
     * nine actions, three ladders and four dispositions and the coding
     * standards say so explicitly. A change to any of these three interfaces
     * now breaks nine free providers, which is the fact this case records.
     */
    public function testTheThreeAutomationSeamsAreKnowinglyFilledAtBoot(): void
    {
        $registries = ManagementFixture::create()->kernel->registries;

        self::assertCount(9, $registries->automationActions->keys());
        self::assertCount(3, $registries->retryPolicies->keys());
        self::assertCount(4, $registries->failurePolicies->keys());
    }

    public function testTheRecipeSeamDeclaresItsSteps(): void
    {
        self::assertSignature(AiRecipeInterface::class, 'steps', [], 'array');
    }

    public function testThePlacementSeamDeclaresItsEightMethodsBesideItsLabel(): void
    {
        self::assertSignature(AiPlacementInterface::class, 'surfaceKey', [], 'string');
        self::assertSignature(AiPlacementInterface::class, 'anchor', [], 'string');
        self::assertSignature(AiPlacementInterface::class, 'renderMode', [], self::AI . 'AiRenderMode');
        self::assertSignature(
            AiPlacementInterface::class,
            'accepts',
            [self::AI . 'TaskDescriptor', self::CONTEXT . 'PlacementContext'],
            'bool',
        );
        self::assertSignature(AiPlacementInterface::class, 'fillableFields', [], 'array');
        self::assertSignature(AiPlacementInterface::class, 'scopeLevel', [], 'string');
        self::assertSignature(AiPlacementInterface::class, 'disclosure', [], self::AI . 'AiDisclosure');
        self::assertSignature(AiPlacementInterface::class, 'offersFor', [self::CONTEXT . 'PlacementContext'], 'array');

        // Nine and no more: the binding from a task to a placement is the
        // task's own declaration, so taskKeys() never arrives.
        self::assertSame(9, self::declaredMethodCount(AiPlacementInterface::class));
    }

    public function testTheSurfaceSeamDeclaresItsScopeLevelAndDisclosureMode(): void
    {
        self::assertSignature(AiSurfaceInterface::class, 'scopeLevel', [], 'string');
        self::assertSignature(
            AiSurfaceInterface::class,
            'defaultDisclosureMode',
            [],
            self::AI . 'AiDisclosureMode',
        );
    }

    public function testTheBridgeSeamDeclaresOneWorkMethodOverARedactedPayload(): void
    {
        self::assertSignature(
            ModelBridgeInterface::class,
            'run',
            [self::AI . 'BridgeRequest', self::CONTEXT . 'BridgeContext'],
            self::AI . 'BridgeResult|' . self::AI . 'BridgeRefusal',
        );

        // The cost vocabulary the bridge denominates in stays a string
        // answering one of the four CostRecord declares, and none of them is
        // a vendor's own product word.
        self::assertSignature(ModelBridgeInterface::class, 'unit', [], 'string');

        // Three and no more. Everything else a bridge can do is a capability
        // probed with instanceof, because the absences are the honest answers
        // a site owner has to be able to give.
        self::assertSame(3, self::declaredMethodCount(ModelBridgeInterface::class));
    }

    public function testThePolicySeamAnswersTheShippedDecisionObject(): void
    {
        self::assertSignature(
            AiPolicyInterface::class,
            'decide',
            [self::AI . 'AiRunIntent', self::CONTEXT . 'AiDecisionContext'],
            'Shondalai\\VidyaLMS\\Core\\Registry\\Notification\\Policy\\PolicyDecision',
        );
    }

    public function testTheRedactorSeamTakesTheResolvedPayloadAndNotTheRequest(): void
    {
        self::assertSignature(
            AiRedactorInterface::class,
            'redact',
            [self::AI . 'AiTaskPayload', self::CONTEXT . 'AiRedactionContext'],
            self::AI . 'RedactedPayload',
        );
    }

    public function testTheFactSeamDeclaresHowManyPeopleItResolvesTo(): void
    {
        self::assertSignature(AiFactProviderInterface::class, 'resolvesToUsers', [], self::AI . 'FactCardinality');
        self::assertSignature(AiFactProviderInterface::class, 'resolve', [self::CONTEXT . 'AiFactContext'], 'mixed');
    }

    public function testTheAcceptorSeamWritesOneArtefactPerDraftKind(): void
    {
        self::assertSignature(
            AiDraftAcceptorInterface::class,
            'accept',
            [self::AI . 'AiDraft', self::CONTEXT . 'AiAcceptContext'],
            self::AI . 'AcceptedArtefact',
        );
    }

    public function testTheActionSeamCarriesNoIdempotencyKeyMethod(): void
    {
        self::assertSignature(AutomationActionInterface::class, 'paramsSchema', [], 'array');
        self::assertSignature(AutomationActionInterface::class, 'applicability', [], 'array');
        self::assertSignature(
            AutomationActionInterface::class,
            'execute',
            [self::CONTEXT . 'AutomationActionContext'],
            self::AUTOMATION . 'ActionOutcome',
        );

        // idempotencyKeyFor() is deliberately absent. A provider free to
        // answer a constant claims one idempotency_keys row for a whole
        // fan-out, and the runner's documented behaviour is to replay the
        // first outcome verbatim, so four hundred subjects execute once and
        // record 399 successful replays. The digest is computed by the core.
        self::assertFalse(method_exists(AutomationActionInterface::class, 'idempotencyKeyFor'));
        self::assertSame(4, self::declaredMethodCount(AutomationActionInterface::class));
    }

    public function testTheRetrySeamAnswersAPlanRatherThanThreeGetters(): void
    {
        self::assertSignature(RetryPolicyInterface::class, 'plan', [], self::AUTOMATION . 'RetryPlan');
        self::assertSame(2, self::declaredMethodCount(RetryPolicyInterface::class));
    }

    public function testTheFailureSeamAnswersOneOfFourDispositions(): void
    {
        self::assertSignature(
            FailurePolicyInterface::class,
            'disposition',
            [],
            self::AUTOMATION . 'FailureDisposition',
        );
        self::assertSame(2, self::declaredMethodCount(FailurePolicyInterface::class));
    }

    /**
     * Every one of the eleven still declares label(), which is what the
     * picker renders and what ProviderInterface pairs with key().
     */
    public function testEverySeamStillDeclaresItsLabel(): void
    {
        foreach (
            [
                AiRecipeInterface::class,
                AiPlacementInterface::class,
                AiSurfaceInterface::class,
                ModelBridgeInterface::class,
                AiPolicyInterface::class,
                AiRedactorInterface::class,
                AiFactProviderInterface::class,
                AiDraftAcceptorInterface::class,
                AutomationActionInterface::class,
                RetryPolicyInterface::class,
                FailurePolicyInterface::class,
            ] as $interface
        ) {
            self::assertSignature($interface, 'label', [], 'string');
        }
    }

    /**
     * The methods the interface declares itself, which is what "and no more"
     * means. key() is inherited from ProviderInterface and is not one of
     * them.
     *
     * @param class-string $interface
     */
    private static function declaredMethodCount(string $interface): int
    {
        $declared = 0;

        foreach ((new \ReflectionClass($interface))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $interface) {
                $declared++;
            }
        }

        return $declared;
    }

    /**
     * @param class-string $interface
     * @param list<string> $parameterTypes
     */
    private static function assertSignature(
        string $interface,
        string $method,
        array $parameterTypes,
        string $returnType,
    ): void {
        self::assertTrue(
            method_exists($interface, $method),
            $interface . '::' . $method . '() is missing',
        );

        $reflected = new \ReflectionMethod($interface, $method);
        $parameters = $reflected->getParameters();

        self::assertCount(
            \count($parameterTypes),
            $parameters,
            $interface . '::' . $method . '() takes the wrong number of parameters',
        );

        foreach ($parameterTypes as $position => $expected) {
            self::assertSame(
                $expected,
                (string) $parameters[$position]->getType(),
                $interface . '::' . $method . '() parameter ' . $position,
            );
        }

        self::assertSame(
            $returnType,
            (string) $reflected->getReturnType(),
            $interface . '::' . $method . '() return type',
        );
    }
}
