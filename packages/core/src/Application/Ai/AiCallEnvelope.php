<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * Everything about one run that every call inside it shares.
 *
 * It exists so AiBridgeCaller can be a single-method collaborator rather than
 * a method with fourteen parameters. The run resolves this once, hands the
 * caller to the task, and the task asks one question of it.
 *
 * There is no Principal here and no domain entity, by the same rule that
 * keeps them off BridgeRequest and BridgeContext: the caller is present as
 * the pseudonym the run minted, and nothing else about the person travels.
 */
final class AiCallEnvelope
{
    /** @var list<string> */
    public readonly array $declaredFactKeys;

    /** @var list<AiRouteCandidate> */
    public readonly array $routes;

    /**
     * @param list<string>           $declaredFactKeys the keys the task declared, never their values
     * @param list<AiRouteCandidate> $routes           the enabled candidates for this task and scope
     */
    private function __construct(
        public readonly string $runKey,
        public readonly string $taskKey,
        public readonly string $callerRef,
        public readonly string $redactorKey,
        public readonly string $redactionLevel,
        public readonly string $siteRef,
        array $declaredFactKeys,
        public readonly string $locale,
        public readonly \DateTimeImmutable $deadline,
        public readonly CostRecord $ceiling,
        array $routes,
        public readonly bool $dryRun,
    ) {
        $this->declaredFactKeys = $declaredFactKeys;
        $this->routes = $routes;
    }

    /**
     * @param list<string>           $declaredFactKeys
     * @param list<AiRouteCandidate> $routes
     */
    public static function create(
        string $runKey,
        string $taskKey,
        string $callerRef,
        string $siteRef,
        \DateTimeImmutable $deadline,
        array $routes = [],
        string $redactorKey = 'strict',
        string $redactionLevel = 'strict',
        array $declaredFactKeys = [],
        string $locale = 'en-GB',
        ?CostRecord $ceiling = null,
        bool $dryRun = false,
    ): self {
        return new self(
            $runKey,
            $taskKey,
            $callerRef,
            $redactorKey,
            $redactionLevel,
            $siteRef,
            $declaredFactKeys,
            $locale,
            $deadline,
            $ceiling ?? CostRecord::unknown(CostRecord::UNIT_NONE),
            AiRouteCandidate::inPositionOrder($routes),
            $dryRun,
        );
    }
}
