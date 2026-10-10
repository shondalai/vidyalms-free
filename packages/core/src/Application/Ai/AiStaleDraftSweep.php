<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftRepository;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftState;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * The eighth scheduled task, and it exists because of a gap the seven others
 * left.
 *
 * Setting retain_until only at a terminal state left every draft in requested,
 * generated, checked or in_review matching no purge predicate at all: exactly
 * the rows nobody ever decided about, which for a feedback draft hold a
 * learner's submission-derived text, surviving instead on the run's four hundred
 * day clock, more than four times the number the policy publishes.
 *
 * The column is now written at insert, which closes the retention half. This
 * closes the other half: **an abandoned draft is DECIDED ABOUT rather than
 * merely aged out.** A draft idle beyond the configured window moves to
 * discarded with the reject reason code `stale_unreviewed`, so the review queue
 * empties honestly and the reason corpus that later tunes the critic can count
 * how often nobody looked.
 *
 * The reason is a code plus free text rather than free text alone, for the same
 * reason every reject reason is: a free-text column cannot be counted.
 */
final class AiStaleDraftSweep
{
    /** The reject reason code an unreviewed draft is discarded under. */
    public const REASON_CODE = 'stale_unreviewed';

    /** How long a draft may sit untouched before the sweep decides about it. */
    public const DEFAULT_IDLE_DAYS = 45;

    /** How many drafts one pass touches. */
    public const DEFAULT_BATCH = 200;

    public function __construct(
        private readonly AiDraftRepository $drafts,
        private readonly ClockPort $clock,
        private readonly int $idleDays = self::DEFAULT_IDLE_DAYS,
        private readonly int $retentionDays = 90,
    ) {
    }

    /**
     * @return array{discarded: int, examined: int}
     */
    public function run(int $batch = self::DEFAULT_BATCH): array
    {
        $now = $this->clock->now();
        $cutoff = $now->modify('-' . max(1, $this->idleDays) . ' days');
        $retainUntil = $now->modify('+' . max(1, $this->retentionDays) . ' days');
        $ids = $this->drafts->idleBefore($cutoff, max(1, $batch));
        $discarded = 0;

        foreach ($ids as $id) {
            $draft = $this->drafts->find($id);

            if ($draft === null || !$draft->state->canBecome(AiDraftState::Discarded)) {
                continue;
            }

            $this->drafts->transition(
                $id,
                AiDraftState::Discarded,
                $now,
                $retainUntil,
                null,
                self::REASON_CODE,
                sprintf(
                    'No reviewer touched this draft for %d days, so it was decided about rather than left to age'
                        . ' out under the run\'s own clock.',
                    max(1, $this->idleDays),
                ),
            );

            ++$discarded;
        }

        return ['discarded' => $discarded, 'examined' => \count($ids)];
    }
}
