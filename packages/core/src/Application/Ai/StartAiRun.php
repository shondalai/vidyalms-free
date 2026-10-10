<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\CancellationSignal;

/**
 * The interactive path: one person, one click, one run.
 *
 * It holds exactly what only it may own, which is its own authorisation and the
 * stop check, and then calls the pipeline. Every invariant lives in the pipeline
 * so that this path and the job path cannot drift apart, which is the failure a
 * second unnamed runner would have produced.
 *
 * **The stop is read before the reservation**, and the same signal is handed to
 * the pipeline so a stop set while the run is in flight is observed between
 * stages. A control that halted every task on the site only at the moment a run
 * began would not be the Article 14 stop it claims to be, and a stop assembled
 * from twelve toggles is not one either.
 *
 * The acceptance gate lives in the policy chain rather than here, so a new
 * placement cannot forget it.
 */
final class StartAiRun
{
    public function __construct(
        private readonly AiRunPipeline $pipeline,
        private readonly AiAccess $access,
        private readonly AiEnablementState $enablement,
    ) {
    }

    public function execute(AiRunRequest $request): AiRunReport
    {
        $this->access->requireAuthoring($request->principal, $request->courseId);

        $signal = CancellationSignal::from(fn (): bool => $this->enablement->stopped());

        return $this->pipeline->run($request, $signal);
    }
}
