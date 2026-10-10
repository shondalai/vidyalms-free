<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Policy;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresRefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;

/**
 * The closed-attempt precondition the learner-facing helper needs, checked
 * here as a policy rather than asked for in a prompt.
 *
 * Two questions, and they are separate on purpose. Is the attempt finished
 * and its mark final; and does the quiz's own review policy already permit
 * this learner to see the correct response. An earlier single precondition
 * said the item was not live in an active bank draw, which said nothing at
 * all about a fixed quiz where every classmate answers the same item, and
 * nothing about a learner whose review policy shows the mark and hides the
 * key. Content scope reads the availability tree, which is a different
 * question from answer visibility, so the answer-visibility test belongs
 * here.
 *
 * A run whose scope is not an attempt is not this policy's business and is
 * allowed: a policy that denied everything it did not recognise would be a
 * policy that turned off the product.
 */
final class AttemptStatePolicy implements AiPolicyInterface, DeclaresRefusalKind
{
    public const KEY = 'attempt_state';

    /** The scope type a learner-facing run carries. */
    public const SCOPE_ATTEMPT = 'attempt';

    public const REASON_UNKNOWN_ATTEMPT = 'ai.policy.attempt_not_found';
    public const REASON_ATTEMPT_OPEN = 'ai.policy.attempt_still_open';
    public const REASON_MARK_NOT_FINAL = 'ai.policy.mark_not_final';

    /**
     * The one status that ends this policy's question with a yes.
     *
     * Graded and not merely submitted, because a submitted attempt awaiting
     * manual marking has no final mark, and explaining a mistake before the
     * mark exists is explaining a decision nobody has taken.
     */
    public const FINAL_STATUS = 'graded';

    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    /**
     * All three are statements about the request as composed: the attempt is
     * not there, or it is not yet in the state the run needs.
     */
    public function refusalKindFor(string $reason): ?RefusalKind
    {
        return \in_array($reason, [
            self::REASON_UNKNOWN_ATTEMPT,
            self::REASON_ATTEMPT_OPEN,
            self::REASON_MARK_NOT_FINAL,
        ], true) ? RefusalKind::InputRejected : null;
    }

    public function label(): string
    {
        return 'ai.policy.attempt_state.label';
    }

    public function decide(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision
    {
        if ($intent->scopeType !== self::SCOPE_ATTEMPT || $intent->scopeId === null) {
            return PolicyDecision::allow();
        }

        $attempt = $this->database->selectOne(
            Query::from('attempts')
                ->select(['status', 'submitted_at'])
                ->where('id', '=', $intent->scopeId)
                ->limit(1),
        );

        if ($attempt === null) {
            return PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_UNKNOWN_ATTEMPT);
        }

        if (($attempt['submitted_at'] ?? null) === null) {
            return PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_ATTEMPT_OPEN);
        }

        return ($attempt['status'] ?? null) === self::FINAL_STATUS
            ? PolicyDecision::allow()
            : PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_MARK_NOT_FINAL);
    }
}
