<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Ai\Pipeline\AiPipelineRun;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRedactorRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\ModelBridgeRegistry;
use Shondalai\VidyaLMS\Core\Registry\Ai\RequiresFacts;

/**
 * The one place a run's bridge caller is built.
 *
 * `AiBridgeCaller::forRun()` is public and takes seven publicly constructible
 * collaborators, so any package could assemble one and reach a bridge without
 * the file ever containing the string `ModelBridgeInterface` that the CI grep
 * looks for. That was not merely a hole in the grep: with nothing seating a
 * caller on the task context, it was the ONLY way a model-backed task could
 * make a call at all, so the evasion was the sanctioned path by default and
 * its attempt rows and reservations went to memory and vanished.
 *
 * So construction moves behind this, the pipeline is its only caller, the
 * ledgers it hands over are the durable ones, and a CI arm refuses
 * `AiBridgeCaller::` and `AiCallEnvelope::` outside `Application/Ai`. The rule
 * is then the same shape as the one that already keeps `ModelBridgeInterface`
 * inside this namespace: stated once, enforced by a grep, and not a habit.
 */
final class AiBridgeCallerFactory
{
    public function __construct(
        private readonly ModelBridgeRegistry $bridges,
        private readonly AiRedactorRegistry $redactors,
        private readonly AiSchemaNarrower $narrower,
        private readonly AiAttemptLedgerInterface $attempts,
        private readonly AiReservationLedgerInterface $reservations,
        private readonly ClockPort $clock,
        private readonly string $siteRef,
    ) {
    }

    /**
     * The caller for one run, or null when the run resolved no route at all.
     *
     * Null rather than a caller over an empty chain, because a task asking
     * `hasBridge()` on a build with nothing configured should be told there is
     * no model rather than handed an object that refuses every call. That is
     * the honest answer on a free site that has enabled nothing, and it is the
     * one the eleven deterministic tasks already act on.
     */
    public function forRun(AiPipelineRun $run, string $callerRef): ?AiBridgeCaller
    {
        if ($run->routes === []) {
            return null;
        }

        $task = $run->task;
        $redacted = $run->redacted;

        return AiBridgeCaller::forRun(
            $this->bridges,
            $this->redactors,
            $this->narrower,
            $this->attempts,
            $this->reservations,
            $this->clock,
            AiCallEnvelope::create(
                $run->runKey(),
                $run->request->taskKey,
                $callerRef,
                $this->siteRef,
                $run->deadline,
                $run->routes,
                $redacted === null ? 'strict' : $redacted->redactorKey,
                $redacted === null ? 'strict' : $redacted->redactionLevel,
                $task instanceof RequiresFacts ? $task->requiredFacts() : [],
                $run->request->locale,
                $run->reserved,
                $run->request->dryRun,
            ),
        );
    }
}
