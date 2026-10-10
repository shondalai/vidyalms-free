<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Policy;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;

/**
 * The estimate against the route's own declared per-run ceiling, in the same
 * cost unit, **where zero refuses**.
 *
 * There is deliberately no value meaning unlimited. A route that declares no
 * per-run ceiling may not be selected, so a provisioning import cannot create
 * an uncapped route, and a site wanting a high ceiling types a high number
 * rather than leaving the field empty: a ceiling with an off switch that is
 * also its default is not a ceiling.
 *
 * **An unknown estimate is not zero.** A bridge that cannot price a call
 * falls back to the route's declared maximum, which passes this policy by
 * construction and is the pessimistic reading on purpose; the ceilings that
 * bind a non-metering route are the two unit-free call counts, not this one.
 *
 * A ceiling denominated in a unit the estimate does not use never fires, and
 * saying so here is better than comparing tokens to money and calling the
 * result a limit.
 */
final class PerRunCostPolicy implements AiPolicyInterface
{
    public const KEY = 'per_run_cost';

    public const REASON_OVER_CEILING = 'ai.policy.per_run_cost_exceeded';
    public const REASON_NO_CEILING = 'ai.policy.route_declares_no_ceiling';

    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.policy.per_run_cost.label';
    }

    public function decide(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision
    {
        $rows = $this->database->selectAll(
            RouteAvailablePolicy::routes($intent->taskKey, $intent->scopeType, $intent->scopeId),
        );

        if ($rows === []) {
            // Whether a route exists at all is the route_available policy's
            // question, and answering it twice with two vocabularies is how a
            // screen ends up showing two reasons for one condition.
            return PolicyDecision::allow();
        }

        $best = 0.0;

        foreach ($rows as $row) {
            $ceiling = $row['max_cost_per_run'] ?? null;
            $best = max($best, \is_numeric($ceiling) ? (float) $ceiling : 0.0);
        }

        if ($best <= 0.0) {
            return PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_NO_CEILING);
        }

        $estimate = $intent->estimate;

        if (!$estimate->isKnown()) {
            return PolicyDecision::allow();
        }

        return ($estimate->amount ?? 0.0) > $best
            ? PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_OVER_CEILING)
            : PolicyDecision::allow();
    }
}
