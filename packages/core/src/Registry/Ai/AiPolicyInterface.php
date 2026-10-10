<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * A site standing decision about what may be sent, to whom, and with what
 * disclosure.
 *
 * Policies veto; they never select. The accepted policy key and version
 * travel on the task context so a run records which decision authorised it.
 *
 * The decision object is the shipped notification one, taken unchanged
 * including its fold, rather than a second decision object with the same two
 * halves. A deny is terminal and a defer is not, and a period ceiling that
 * defers to the window boundary is exactly the case a deny-set cannot
 * express. PolicyDecision's own docblock publishes the target key convention
 * a channel-less target uses.
 */
interface AiPolicyInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * What this policy says about the run being proposed, before anything is
     * spent. The intent carries the shape of the run and never its payload,
     * because a policy that could read the segments would be a second
     * redactor.
     */
    public function decide(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision;
}
