<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Policy;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresEnablementLevel;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresRefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;

/**
 * Has this person accepted the data-use policy that is in force.
 *
 * **The version is the whole departure.** The consent register this copies
 * carries a user, a context and a time, and no version at all, so a changed
 * policy cannot force re-acceptance and no audit row can say which policy was
 * in force. Here the version sits on the acceptance row, is stamped onto
 * every run, and a changed version re-prompts.
 *
 * **The gate lives here rather than in each placement**, so a new placement
 * cannot forget it, and a run refused for an unaccepted or superseded version
 * is a refusal with a machine-readable key rather than an exception.
 *
 * The fourth level of enablement is this one, and it is per person: the site
 * enabling AI, the site enabling the placement and a route enabling the task
 * are the other three, and none of them substitutes for somebody having read
 * what leaves the site.
 */
final class PolicyAcceptedPolicy implements AiPolicyInterface, DeclaresRefusalKind, DeclaresEnablementLevel
{
    public const KEY = 'policy_accepted';

    public const REASON_NOT_ACCEPTED = 'ai.policy.not_accepted';
    public const REASON_SUPERSEDED = 'ai.policy.superseded';
    public const REASON_NO_POLICY = 'ai.policy.none_published';

    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function enablementLevels(): array
    {
        return [[
            'level' => 'person',
            'setting' => 'ai_policy_acceptances.policy_version',
            'stored_in' => 'ai_policy_acceptances',
        ]];
    }

    /**
     * A missing acceptance is a statement about the request as composed by a
     * person who has not consented, which is `input_rejected`; a site with no
     * published policy at all is a thing an administrator configures.
     */
    public function refusalKindFor(string $reason): ?RefusalKind
    {
        return match ($reason) {
            self::REASON_NOT_ACCEPTED, self::REASON_SUPERSEDED => RefusalKind::InputRejected,
            self::REASON_NO_POLICY => RefusalKind::NotConfigured,
            default => null,
        };
    }

    public function label(): string
    {
        return 'ai.policy.policy_accepted.label';
    }

    public function decide(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision
    {
        $active = $this->activeVersion($context->now);

        if ($active === null) {
            // A site with no published policy has not told anybody anything,
            // and a run under no policy is exactly what the version column
            // exists to make impossible.
            return PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_NO_POLICY);
        }

        $accepted = $this->database->selectOne(
            Query::from('ai_policy_acceptances')
                ->select(['policy_version'])
                ->where('user_id', '=', $context->principal->userId)
                ->where('policy_version', '=', $active)
                ->limit(1),
        );

        if ($accepted !== null) {
            return PolicyDecision::allow();
        }

        $any = $this->database->count(
            Query::from('ai_policy_acceptances')
                ->select(['policy_version'])
                ->where('user_id', '=', $context->principal->userId),
        );

        return PolicyDecision::deny(
            [PolicyDecision::TARGET_AI_RUN],
            $any > 0 ? self::REASON_SUPERSEDED : self::REASON_NOT_ACCEPTED,
        );
    }

    /**
     * The highest active version already in force, or null.
     *
     * Effective-from is compared against the decision clock rather than
     * against the database's own, so a policy scheduled for tomorrow does not
     * silently start refusing today's runs on a site whose database sits in a
     * different timezone.
     */
    private function activeVersion(\DateTimeImmutable $now): ?int
    {
        $row = $this->database->selectOne(
            Query::from('ai_policies')
                ->select(['version'])
                ->where('state', '=', 'active')
                ->where('effective_from', '<=', $now->format('Y-m-d H:i:s'))
                ->orderBy('version', Query::SORT_DESC)
                ->limit(1),
        );

        $version = $row['version'] ?? null;

        return \is_int($version) ? $version : (\is_numeric($version) ? (int) $version : null);
    }
}
