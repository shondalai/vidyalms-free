<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Policy;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresRefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;

/**
 * What this person may already see, answered by the machinery that already
 * owns that question.
 *
 * It resolves through the shipped course access service rather than
 * reimplementing a visibility rule, for the reason the whole design keeps
 * repeating: a second answer to a question the product already answers is a
 * second answer that will drift. The availability tree owns retrieval scope
 * and this policy asks it rather than replacing it.
 *
 * A run at site scope is not asking about content and is allowed here; the
 * capabilities that gate a site-scope run are checked at its own front door.
 * A run naming a course is refused unless this person could open the course
 * without any AI involved at all, which is the only reading that cannot leak:
 * a helper may never volunteer material its operator was not already entitled
 * to see.
 */
final class ContentScopePolicy implements AiPolicyInterface, DeclaresRefusalKind
{
    public const KEY = 'content_scope';

    public const REASON_OUT_OF_SCOPE = 'ai.policy.content_out_of_scope';

    /** The scope types that name a course this policy can ask about. */
    public const COURSE_SCOPES = ['course', 'module', 'lesson_course'];

    public function __construct(private readonly CourseAccess $courses)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.policy.content_scope.label';
    }

    /**
     * Out of scope is a statement about the request as composed, which is
     * `input_rejected`, and never about a budget.
     */
    public function refusalKindFor(string $reason): ?RefusalKind
    {
        return $reason === self::REASON_OUT_OF_SCOPE ? RefusalKind::InputRejected : null;
    }

    public function decide(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision
    {
        if (!\in_array($intent->scopeType, self::COURSE_SCOPES, true) || $intent->scopeId === null) {
            return PolicyDecision::allow();
        }

        $mayRead = $this->courses->can($context->principal, $intent->scopeId, Capability::TEACH)
            || $this->courses->can($context->principal, $intent->scopeId, Capability::MANAGE);

        return $mayRead
            ? PolicyDecision::allow()
            : PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_OUT_OF_SCOPE);
    }
}
