<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Policy;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunIntent;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresEnablementLevel;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresRefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\Context\AiDecisionContext;
use Shondalai\VidyaLMS\Core\Registry\Notification\Policy\PolicyDecision;

/**
 * The third level of enablement: does any enabled route serve this task at
 * this scope.
 *
 * It is a policy rather than a discovery inside the runner so the answer
 * arrives **before anything is spent** and reaches the placement as a
 * rendered reason rather than as a failure at click time. A person told that
 * no model is configured for question generation can go and configure one; a
 * person whose button threw cannot.
 *
 * The scope reading is deliberately generous in one direction only: a route
 * declared at site scope serves every course, and a route declared for one
 * course serves only that course. A ceiling would be read the other way
 * round, which is why ceilings are different policies.
 */
final class RouteAvailablePolicy implements AiPolicyInterface, DeclaresRefusalKind, DeclaresEnablementLevel
{
    public const KEY = 'route_available';

    public const REASON_NOT_CONFIGURED = 'ai.policy.no_route';

    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'ai.policy.route_available.label';
    }

    public function enablementLevels(): array
    {
        return [['level' => 'route', 'setting' => 'ai_routes.enabled', 'stored_in' => 'ai_routes']];
    }

    /** No route is a thing an administrator configures, not a budget. */
    public function refusalKindFor(string $reason): ?RefusalKind
    {
        return $reason === self::REASON_NOT_CONFIGURED ? RefusalKind::NotConfigured : null;
    }

    public function decide(AiRunIntent $intent, AiDecisionContext $context): PolicyDecision
    {
        return self::countRoutes($this->database, $intent->taskKey, $intent->scopeType, $intent->scopeId) > 0
            ? PolicyDecision::allow()
            : PolicyDecision::deny([PolicyDecision::TARGET_AI_RUN], self::REASON_NOT_CONFIGURED);
    }

    /**
     * Enabled routes for this task, at this scope or at site scope above it.
     *
     * Shared with the per-run cost ceiling so the two policies cannot come to
     * different conclusions about which rows exist.
     */
    public static function countRoutes(
        DatabasePort $database,
        string $taskKey,
        string $scopeType,
        ?int $scopeId,
    ): int {
        return $database->count(self::routes($taskKey, $scopeType, $scopeId));
    }

    public static function routes(string $taskKey, string $scopeType, ?int $scopeId): Query
    {
        $query = Query::from('ai_routes')
            ->select(['id', 'bridge_key', 'model', 'prompt_ref', 'params', 'position', 'max_cost_per_run'])
            ->where('task_key', '=', $taskKey)
            ->where('enabled', '=', 1)
            ->orderBy('position', Query::SORT_ASC);

        if ($scopeType === 'site' || $scopeId === null || $scopeId <= 0) {
            return $query->where('scope_type', '=', 'site')->where('scope_id', '=', 0);
        }

        // A course route and the site routes above it are both candidates,
        // which is what makes a per-task chain worth having: a site runs a
        // cheap model everywhere and a strong one in one course.
        return $query->whereAnyOf(
            Predicate::allOf(Predicate::compare('scope_type', '=', 'site'), Predicate::compare('scope_id', '=', 0)),
            Predicate::allOf(
                Predicate::compare('scope_type', '=', $scopeType),
                Predicate::compare('scope_id', '=', $scopeId),
            ),
        );
    }
}
