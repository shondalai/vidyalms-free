<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostEstimate;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresRefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;

/**
 * Stage 2. Folds every registered policy over the intended run.
 *
 * Policies veto or defer and never select, so the object handed to them is the
 * shape of the intended run and not the run itself: no payload, no segments and
 * no bridge handle, because a policy that could read the payload would be a
 * second redactor and one that held a bridge would be a second caller.
 *
 * The fold is PolicyDecision's own, taken unchanged from the notification
 * engine, including its rule that a deny anywhere wins over a defer for the same
 * target and the latest deferral wins among deferrals so a run never moves
 * earlier. Reusing the object gives the product one vocabulary, one fold
 * implementation to get right, and an admin screen that reads deny and defer the
 * same way for mail and for AI.
 *
 * **The miss direction diverges from the notification precedent on purpose.** An
 * unresolvable notification policy denies nothing, which is right for a message.
 * Consent, a spend ceiling and a redaction level are gates, and an unresolved
 * gate fails closed, so a policy that throws refuses the run rather than being
 * skipped. Inheriting the notification direction here would make a missing
 * consent policy an open door.
 */
final class EvaluatePoliciesStage implements AiRunStageInterface
{
    public function __construct(
        private readonly AiPolicyRegistry $policies,
        private readonly AiDecisionContextFactory $contexts,
    ) {
    }

    public function name(): string
    {
        return 'EvaluatePolicies';
    }

    public function runsAfterHalt(): bool
    {
        return false;
    }

    public function handle(AiPipelineRun $run): void
    {
        $intent = AiRunIntent::forTask(
            $run->request->taskKey,
            $run->request->scopeType,
            $run->request->scopeId,
            $run->placementKey,
            max(1, \count($run->subjects)),
            CostEstimate::unknown(),
            $run->request->dryRun,
            // The subject and the course travel with the intent so a gate can
            // bind the thing the run points at to the scope the caller was
            // authorised for, before any fact resolves and before anything is
            // spent.
            $run->request->task->subjectType,
            $run->request->task->subjectId,
            $run->request->courseId,
            $run->request->actorKind,
        );

        $context = $this->contexts->for($run);
        $decision = PolicyDecision::allow();

        foreach ($this->policies->all() as $key => $policy) {
            if (!$policy instanceof AiPolicyInterface) {
                continue;
            }

            try {
                $decision = $decision->merge($policy->decide($intent, $context));
            } catch (\Throwable $unresolved) {
                // An unresolved gate fails closed. The key is named so a site
                // owner can see which policy is broken rather than being told
                // only that AI is off.
                $run->refuse(RefusalKind::NotConfigured, 'ai.policy.unresolvable', [
                    'policy' => (string) $key,
                    'reason' => $unresolved->getMessage(),
                ]);

                return;
            }
        }

        $target = PolicyDecision::TARGET_AI_RUN;

        if (isset($decision->denied[$target])) {
            $reason = $decision->denied[$target];
            // The refusing policy names its own kind. Everything used to be
            // `ceiling_exceeded`, so a disabled panel, an unaccepted data-use
            // policy, a stopped site and a subject out of scope were all
            // counted in the budget bucket and all inherited the budget
            // retry decision. The reason travels as the message key so the
            // run row and the usage report can name the cause and not only
            // the family.
            $run->refuse($this->kindOf($reason), $reason, ['reason' => $reason]);

            return;
        }

        if (isset($decision->deferred[$target])) {
            $run->defer(
                $decision->deferred[$target],
                'A ceiling policy deferred this run to ' . $decision->deferred[$target]->format('Y-m-d H:i:s')
                    . ', which is a real answer a deny cannot express.',
            );
        }
    }

    /**
     * The kind the refusing policy declares for its own reason.
     *
     * Asked of every registered policy rather than read off a map inside this
     * class, because a map here would be a core edit for every vendor gate.
     * A policy that declares nothing keeps `ceiling_exceeded`, which is what
     * the six shipped ceilings mean literally.
     */
    private function kindOf(string $reason): RefusalKind
    {
        foreach ($this->policies->all() as $policy) {
            if (!$policy instanceof DeclaresRefusalKind) {
                continue;
            }

            $kind = $policy->refusalKindFor($reason);

            if ($kind !== null) {
                return $kind;
            }
        }

        return RefusalKind::CeilingExceeded;
    }
}
