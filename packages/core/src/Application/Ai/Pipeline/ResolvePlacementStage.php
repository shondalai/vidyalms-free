<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiPlacementRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\RefusalKind;
use Shondalai\VidyaLMS\Core\Registry\UnknownProviderException;

/**
 * Stage 1. Resolves the task and, when the run names one, the placement.
 *
 * The two registry misses answer differently, and the difference is published
 * rather than accidental. A task nothing registers refuses `not_configured`,
 * because the run cannot happen; a placement nothing registers also refuses,
 * because a run naming a placement is a run whose permissions were resolved
 * against a panel that no longer exists, and continuing would run it ungated.
 * The placement's OWN miss direction, that the panel simply does not render, is
 * the surface's answer and not the runner's: a screen that lost a panel is fine,
 * and a run that lost its gate is not.
 */
final class ResolvePlacementStage implements AiRunStageInterface
{
    public function __construct(
        private readonly AiTaskRegistry $tasks,
        private readonly AiPlacementRegistry $placements,
    ) {
    }

    public function name(): string
    {
        return 'ResolvePlacement';
    }

    public function runsAfterHalt(): bool
    {
        return false;
    }

    public function handle(AiPipelineRun $run): void
    {
        try {
            $task = $this->tasks->get($run->request->taskKey);
        } catch (UnknownProviderException) {
            $run->refuse(RefusalKind::NotConfigured, 'ai.task.unregistered', [
                'task' => $run->request->taskKey,
            ]);

            return;
        }

        if (!$task instanceof AiTaskInterface) {
            $run->refuse(RefusalKind::NotConfigured, 'ai.task.unregistered', [
                'task' => $run->request->taskKey,
            ]);

            return;
        }

        $run->task = $task;

        if ($run->placementKey === null) {
            return;
        }

        if (!$this->placements->has($run->placementKey)) {
            $run->refuse(RefusalKind::NotConfigured, 'ai.placement.unregistered', [
                'placement' => $run->placementKey,
            ]);
        }
    }
}
