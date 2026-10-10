<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Application\Ai\AiRouteCandidate;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunReservation;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Provider\Ai\Policy\RouteAvailablePolicy;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Ai\EvaluatedInsideTheReservation;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostEstimate;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;
use Shondalai\VidyaLMS\Core\Registry\Ai\EstimatesCost;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\AiTaskContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;

/**
 * Stage 6. Estimates the run and writes the reserved ledger row.
 *
 * **The ceiling evaluation and the write of the reserved row happen inside one
 * DatabasePort::transactional() call.** Two concurrent runs cannot then both
 * read the same headroom, which is the failure a ceiling read outside a
 * transaction has under fan-out: a course build is dozens of calls issued from a
 * binned outbox with two runners over one pending row as the normal case.
 *
 * That sentence used to be false. The policy fold is stage two and this is
 * stage six, and the transaction wrapped the insert alone, so the windowed
 * total was read four stages and no transaction earlier. What is recomputed
 * here is not the whole chain, which would ask consent and the site switch a
 * second time for nothing: it is the policies that declare
 * {@see EvaluatedInsideTheReservation}, which are the ones whose answer
 * depends on a total another concurrent run is also writing.
 *
 * The reserved figure is what the windowed sum reads as
 * GREATEST(cost_charged, cost_reserved), because cost_charged is written only at
 * the terminal state. A run that never reaches a terminal state has its
 * reservation released by the retention sweep once its deadline has passed,
 * which is why abandonedBefore() and releaseReservation() exist on the
 * repository rather than being left as an operational chore.
 *
 * **The subject rows are written in the same transaction as the run row.** A
 * subject row that can arrive later is a subject row that can be missing when
 * the right-to-know view asks, and the runs that touch the most learners are
 * exactly the multi-subject ones.
 *
 * A task that declares no estimate is not guessed at: the ceiling check uses the
 * route's declared per-run maximum, and a route declaring none may not be
 * selected at all, because a ceiling whose default is off is not a ceiling.
 *
 * **This is also where routes stop being write-only.** `ai_routes` rows were
 * selected by two policies that only counted them, and the run row's route id
 * was never assigned, its bridge key was the literal string `null` and its
 * model was never set: a site with an enabled route naming a real bridge still
 * recorded `bridge_key: null, model: NULL, route_id: NULL`, so the free smoke
 * assertion that the run names the null bridge was asserting a constant. The
 * rows are mapped into {@see AiRouteCandidate} here, in position order, the
 * first selectable one whose bridge is registered is stamped on the run, and
 * the whole chain travels to the task stage so a call can walk it.
 */
final class EstimateAndReserveStage implements AiRunStageInterface
{
    /** The retention window in days for a run row, before the policy overrides it. */
    public const DEFAULT_RETENTION_DAYS = 400;

    /** The floor Article 26(6) sets, enforced in the write path and never in the column. */
    public const RETENTION_FLOOR_DAYS = 180;

    public function __construct(
        private readonly AiRunRepository $runs,
        private readonly DatabasePort $database,
        private readonly string $siteRef,
        private readonly int $retentionDays = self::DEFAULT_RETENTION_DAYS,
        /**
         * Where a route's bridge key is resolved to a provider.
         *
         * Trailing and defaulted so a stage built by hand still constructs;
         * absent, no candidate resolves and the run keeps the free build's
         * `null` bridge, which is what it had before routes were read at all.
         */
        private readonly ?ModelBridgeRegistry $bridges = null,
        /**
         * The policy chain and the context it decides in, for the windowed
         * half of the fold. Both trailing and defaulted: absent, the
         * reservation is written without the re-read, which is what it did
         * before and is still correct on a build with no ceilings configured.
         */
        private readonly ?AiPolicyRegistry $policies = null,
        private readonly ?AiDecisionContextFactory $contexts = null,
    ) {
    }

    public function name(): string
    {
        return 'EstimateAndReserve';
    }

    public function runsAfterHalt(): bool
    {
        return false;
    }

    public function handle(AiPipelineRun $run): void
    {
        $this->selectRoute($run);
        $estimate = $this->estimate($run);
        $run->reserved = CostRecord::of($estimate->unit, $estimate->amount);

        $reservation = AiRunReservation::create(
            $run->runKey(),
            $run->request->taskKey,
            $run->inputsDigest(),
            $this->retainUntil($run),
            1,
            $run->request->jobId,
            $run->placementKey,
            $run->routeId,
            $run->bridgeKey,
            $run->model,
            0,
            $run->request->principal->isGuest() ? null : $run->request->principal->userId,
            $run->request->actorKind,
            $run->request->ruleId,
            $run->request->onBehalfOfUserId,
            $run->request->task->subjectType,
            $run->request->task->subjectId,
            $run->request->courseId,
            $run->request->organisationId(),
            $run->request->locale,
            $run->manifest === [] ? null : ['fields' => $run->manifest],
            1,
            $run->request->dryRun,
            $estimate->unit,
            $estimate->amount,
        );

        $subjects = $run->subjects;

        try {
            $run->runId = $this->database->transactional(function () use ($run, $reservation, $subjects): int {
                $this->requireHeadroom($run);

                return $this->runs->reserve($reservation, $subjects);
            });
        } catch (CeilingReachedDuringReservation $reached) {
            if ($reached->until !== null) {
                $run->defer($reached->until, $reached->getMessage());

                return;
            }

            $run->refuse(RefusalKind::CeilingExceeded, $reached->reason, ['reason' => $reached->reason]);
        } catch (\Throwable $refused) {
            $run->refuse(RefusalKind::NotConfigured, 'ai.run.unreservable', [
                'reason' => $refused->getMessage(),
            ]);
        }
    }

    /**
     * The windowed half of the policy fold, re-read inside the reserving
     * transaction.
     *
     * It throws rather than returning, because the reservation must not be
     * written when the headroom has gone and a transactional callback with two
     * outcomes is a callback that has to invent its own control flow.
     */
    private function requireHeadroom(AiPipelineRun $run): void
    {
        if ($this->policies === null || $this->contexts === null) {
            return;
        }

        $windowed = [];

        foreach ($this->policies->all() as $policy) {
            if ($policy instanceof AiPolicyInterface && $policy instanceof EvaluatedInsideTheReservation) {
                $windowed[] = $policy;
            }
        }

        if ($windowed === []) {
            return;
        }

        $intent = AiRunIntent::forTask(
            $run->request->taskKey,
            $run->request->scopeType,
            $run->request->scopeId,
            $run->placementKey,
            max(1, \count($run->subjects)),
            CostEstimate::of($run->reserved->unit, $run->reserved->amount ?? 0.0),
            $run->request->dryRun,
            $run->request->task->subjectType,
            $run->request->task->subjectId,
            $run->request->courseId,
            $run->request->actorKind,
        );

        $context = $this->contexts->for($run);
        $decision = PolicyDecision::allow();

        foreach ($windowed as $policy) {
            $decision = $decision->merge($policy->decide($intent, $context));
        }

        $target = PolicyDecision::TARGET_AI_RUN;

        if (isset($decision->denied[$target])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new CeilingReachedDuringReservation($decision->denied[$target], null);
        }

        if (isset($decision->deferred[$target])) {
            throw new CeilingReachedDuringReservation(
                'ai.policy.ceiling_reached',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $decision->deferred[$target],
            );
        }
    }

    /**
     * The enabled routes for this task and scope, mapped into candidates and
     * stamped on the run.
     *
     * The chain is read in the same query the route-availability policy reads,
     * shared deliberately, so the policy that said a route exists and the
     * stage that selects one cannot come to different conclusions about which
     * rows there are.
     *
     * The selected candidate is the first in position order whose bridge is
     * registered and whose per-run ceiling is a number a spend can be compared
     * against. A row naming a bridge nothing registers is left in the chain,
     * because the caller records the unresolved attempt against it rather than
     * pretending the row is not there.
     */
    private function selectRoute(AiPipelineRun $run): void
    {
        if ($this->bridges === null) {
            return;
        }

        $rows = $this->database->selectAll(RouteAvailablePolicy::routes(
            $run->request->taskKey,
            $run->request->scopeType,
            $run->request->scopeId,
        ));

        $candidates = [];
        $selected = null;
        $selectedId = null;

        foreach ($rows as $row) {
            $bridgeKey = (string) ($row['bridge_key'] ?? '');

            if ($bridgeKey === '') {
                continue;
            }

            $unit = $this->bridges->has($bridgeKey) && $this->bridges->get($bridgeKey) instanceof ModelBridgeInterface
                ? $this->bridges->get($bridgeKey)->unit()
                : CostRecord::UNIT_NONE;

            $candidate = AiRouteCandidate::of(
                $bridgeKey,
                (string) ($row['model'] ?? ''),
                CostRecord::of($unit, (float) ($row['max_cost_per_run'] ?? 0)),
                (int) ($row['position'] ?? 0),
                \is_string($row['prompt_ref'] ?? null) ? $row['prompt_ref'] : null,
                StoredShape::bodyOf(self::decoded($row['params'] ?? null)),
            );

            $candidates[] = $candidate;

            if ($selected === null && $candidate->isSelectable() && $this->bridges->has($bridgeKey)) {
                $selected = $candidate;
                $selectedId = isset($row['id']) ? (int) $row['id'] : null;
            }
        }

        $run->routes = AiRouteCandidate::inPositionOrder($candidates);

        if ($selected === null) {
            return;
        }

        $run->routeId = $selectedId;
        $run->bridgeKey = $selected->bridgeKey;
        $run->model = $selected->model === '' ? null : $selected->model;
    }

    /**
     * @return array<string, mixed>
     */
    private static function decoded(mixed $stored): array
    {
        if (\is_array($stored)) {
            /** @var array<string, mixed> $stored */
            return $stored;
        }

        if (!\is_string($stored) || $stored === '') {
            return [];
        }

        $decoded = json_decode($stored, true);

        /** @var array<string, mixed> $decoded */
        return \is_array($decoded) ? $decoded : [];
    }

    private function estimate(AiPipelineRun $run): CostEstimate
    {
        $task = $run->task;

        if (!$task instanceof EstimatesCost) {
            // Absent means the ceiling check uses the route's declared per-run
            // maximum, never an optimistic guess. On the free build there is no
            // route and no model, so the honest answer is a free run rather than
            // an unknown one.
            return CostEstimate::free();
        }

        return $task->estimate($run->request->task, AiTaskContext::create(
            $this->callerRef($run),
            $run->runKey(),
            $run->now,
            $run->deadline,
            $run->placementKey,
            null,
            1,
            $run->request->locale,
            $run->reserved,
            $run->request->dryRun,
            1,
            $run->facts,
            // The stop is threaded here as well as into the task stage, so an
            // estimator with an inner loop can ask.
            $run->stop,
        ));
    }

    /**
     * The pseudonymous caller, which is what crosses a model boundary instead of
     * a user id, a name or an email. The site can still determine who made the
     * request; the model provider cannot.
     */
    private function callerRef(AiPipelineRun $run): string
    {
        return hash('sha256', $this->siteRef . $run->request->principal->userId);
    }

    private function retainUntil(AiPipelineRun $run): \DateTimeImmutable
    {
        // The floor is enforced here, in the write path, so a site owner cannot
        // silently break Article 26(6) by typing 30 into a policy.
        $days = max(self::RETENTION_FLOOR_DAYS, $this->retentionDays);

        return $run->now->modify('+' . $days . ' days');
    }
}
