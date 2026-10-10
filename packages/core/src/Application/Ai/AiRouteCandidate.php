<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * One `ai_routes` row, as the caller reads it.
 *
 * A bridge is a class and a route is a row: the credential, the model, the
 * prompt reference and the params belong to the row, which is why a site runs
 * a cheap model for a critique and a strong one for an outline without
 * maintaining two provider instances.
 *
 * **maxCostPerRun declares the route's ceiling and zero refuses.** There is
 * deliberately no value meaning unlimited: a route that declares no per-run
 * ceiling may not be selected, so a provisioning import cannot create an
 * uncapped route, and a site wanting a high ceiling types a high number
 * rather than leaving the field empty. A ceiling with an off switch that is
 * also its default is not a ceiling.
 */
final class AiRouteCandidate
{
    /** @var array<string, mixed> */
    public readonly array $params;

    /**
     * @param array<string, mixed> $params
     */
    private function __construct(
        public readonly string $bridgeKey,
        public readonly string $model,
        public readonly ?string $promptRef,
        array $params,
        public readonly int $position,
        public readonly CostRecord $maxCostPerRun,
    ) {
        $this->params = $params;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function of(
        string $bridgeKey,
        string $model,
        CostRecord $maxCostPerRun,
        int $position = 0,
        ?string $promptRef = null,
        array $params = [],
    ): self {
        return new self($bridgeKey, $model, $promptRef, $params, $position, $maxCostPerRun);
    }

    /**
     * Whether this route may be selected at all.
     *
     * An unknown ceiling and a zero ceiling both refuse, and they refuse for
     * the same reason: neither is a number a spend can be compared against.
     */
    public function isSelectable(): bool
    {
        return $this->maxCostPerRun->isKnown() && ($this->maxCostPerRun->amount ?? 0.0) > 0.0;
    }

    /**
     * Order a chain the way the runner walks it: position ascending, and a
     * stable fall back on the bridge key so two rows sharing a position do
     * not reorder between reads.
     *
     * @param list<self> $candidates
     *
     * @return list<self>
     */
    public static function inPositionOrder(array $candidates): array
    {
        usort(
            $candidates,
            static fn (self $left, self $right): int => [$left->position, $left->bridgeKey, $left->model]
                <=> [$right->position, $right->bridgeKey, $right->model],
        );

        return $candidates;
    }
}
