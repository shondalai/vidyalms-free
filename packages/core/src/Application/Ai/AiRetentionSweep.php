<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftRepository;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * The daily purge, run per artefact class because one number is not honest.
 *
 * | Artefact | Default | Floor |
 * |---|---|---|
 * | runs and attempts | 400 days | 180, enforced |
 * | drafts, whatever their state | 90 days | none |
 * | provenance | for the life of the artefact | not purgeable |
 * | grounding chunks | deleted with their source | none |
 *
 * The two numbers differ because the artefacts differ. A deployer log is
 * required for a period appropriate to the purpose and at least six months, so
 * the run's floor is enforced in the write path where the instant is computed and
 * not in the column. A discarded feedback draft about a named learner is personal
 * data with no remaining purpose, and so is one nobody ever opened, so ninety days
 * is generous rather than tight. An ACCEPTED draft's payload is purged on the
 * same ninety days, because the artefact now lives in its own table with its own
 * history while the editorial-responsibility record is what survives.
 *
 * **ai_artefact_provenance is never touched, and the mechanism is the absence of
 * a method rather than a condition in a loop.** AiProvenanceRepository has no
 * purge and no delete, so a sweep that wanted to reach those rows would have to
 * add one and argue for it.
 *
 * The sweep also releases the reservation of any run left non-terminal past its
 * deadline. A reservation exists to stop two concurrent runs reading the same
 * headroom, and one that never settles would hold that headroom for ever, which
 * would eventually read as a site permanently over its ceiling.
 */
final class AiRetentionSweep
{
    /** How many rows one pass touches, so a sweep is bounded rather than a table scan. */
    public const DEFAULT_BATCH = 500;

    /**
     * How long after its creation a non-terminal run is presumed abandoned.
     *
     * A day rather than an hour, because a queued build legitimately sits
     * reserved while the host's task runner works through its queue, and a sweep
     * that released those would take the headroom away from work that is about to
     * use it.
     */
    public const ABANDONED_AFTER_HOURS = 24;

    public function __construct(
        private readonly AiRunRepository $runs,
        private readonly AiDraftRepository $drafts,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return array{runs_purged: int, drafts_purged: int, reservations_released: int}
     */
    public function run(int $batch = self::DEFAULT_BATCH): array
    {
        $now = $this->clock->now();
        $batch = max(1, $batch);

        // Drafts first, so a run purged in the same pass does not leave its
        // drafts to be found by the next one. The run purge cascades anyway; this
        // is the pass that reaches drafts whose own ninety days expired while
        // their run's four hundred had not.
        $draftIds = $this->drafts->expiredBefore($now, $batch);
        $draftsPurged = $draftIds === [] ? 0 : $this->drafts->purge($draftIds);

        $runIds = $this->runs->expiredBefore($now, $batch);
        $runsPurged = $runIds === [] ? 0 : $this->runs->purge($runIds);

        $released = 0;
        $cutoff = $now->modify('-' . self::ABANDONED_AFTER_HOURS . ' hours');

        foreach ($this->runs->abandonedBefore($cutoff, $batch) as $runId) {
            $this->runs->releaseReservation($runId, $now);
            ++$released;
        }

        return [
            'runs_purged' => $runsPurged,
            'drafts_purged' => $draftsPurged,
            'reservations_released' => $released,
        ];
    }
}
