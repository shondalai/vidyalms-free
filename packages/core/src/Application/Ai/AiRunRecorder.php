<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftCandidate;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiRunProgress;
use Shondalai\VidyaLMS\Core\Registry\Ai\CostRecord;

/**
 * The sink a job's units report through, and the job log's only author.
 *
 * It mirrors ImportRecorder exactly, because the job model is the import
 * pipeline copied rather than reinvented, and the two callbacks are the same two
 * for the same reason: the note callback writes progress and moves updated_at,
 * which is the heartbeat, and the heartbeat callback moves updated_at alone so a
 * long silent phase is not read as a dead process.
 *
 * Three members ImportRecorder has no use for:
 *
 * - drafted() counts what the run proposed, so a job log says "eleven of twenty
 *   drafted" rather than "finished";
 * - spent() accumulates in the unit the bridge declared, and refuses to add two
 *   units together. A total that silently summed tokens and provider units would
 *   be a number a site owner could not act on, so a second unit starts a second
 *   total and the log says so.
 * - stopRequested() is asked between units, which is what makes a four-minute
 *   build stop within one unit rather than at the end of it.
 */
final class AiRunRecorder implements AiRunProgress
{
    /** The counter key for units the run proposed a draft for. */
    public const TOTAL_DRAFTED = 'drafted';

    /** The counter key for units that produced nothing. */
    public const TOTAL_EMPTY = 'empty';

    /** The counter key for units a refusal ended. */
    public const TOTAL_REFUSED = 'refused';

    /** @var list<ImportNote> */
    private array $notes = [];

    /** @var array<string, int> */
    private array $totals = [];

    /** @var array<string, float> */
    private array $spendByUnit = [];

    /** @var \Closure(self): void|null */
    private readonly ?\Closure $onNote;

    /** @var \Closure(): void|null */
    private readonly ?\Closure $onHeartbeat;

    /** @var \Closure(): bool|null */
    private readonly ?\Closure $stop;

    /**
     * @param \Closure(self): void|null $onNote      called once per note, and it is
     *                                              the heartbeat
     * @param \Closure(): void|null     $onHeartbeat called by a phase with nothing
     *                                              to say
     * @param \Closure(): bool|null     $stop        asked, never cached
     */
    public function __construct(
        private readonly int $jobId,
        private readonly bool $dryRun,
        ?\Closure $onNote = null,
        ?\Closure $onHeartbeat = null,
        ?\Closure $stop = null,
    ) {
        $this->onNote = $onNote;
        $this->onHeartbeat = $onHeartbeat;
        $this->stop = $stop;
    }

    public function jobId(): int
    {
        return $this->jobId;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function note(string $level, string $message): void
    {
        $this->notes[] = ImportNote::of($level, $message);

        if ($this->onNote === null) {
            return;
        }

        ($this->onNote)($this);
    }

    public function counted(string $what, int $delta = 1): void
    {
        $this->totals[$what] = ($this->totals[$what] ?? 0) + $delta;
    }

    public function drafted(AiDraftCandidate $candidate): void
    {
        $this->counted(self::TOTAL_DRAFTED);
        $this->counted('drafted.' . $candidate->draftKind);
    }

    public function spent(CostRecord $cost): void
    {
        if (!$cost->isKnown()) {
            // Unknown is recorded as unknown and never as zero. A usage report
            // saying a site spent nothing is worse than one saying it does not
            // know, and the counter is what carries that distinction into a log.
            $this->counted('spend.unknown.' . $cost->unit);

            return;
        }

        $this->spendByUnit[$cost->unit] = ($this->spendByUnit[$cost->unit] ?? 0.0) + (float) $cost->amount;
    }

    public function stopRequested(): bool
    {
        return $this->stop !== null && ($this->stop)();
    }

    /** Moves the heartbeat without writing a note, for a phase with nothing to say. */
    public function beat(): void
    {
        if ($this->onHeartbeat === null) {
            return;
        }

        ($this->onHeartbeat)();
    }

    /**
     * @return array<string, int>
     */
    public function totals(): array
    {
        $totals = $this->totals;

        foreach ($this->spendByUnit as $unit => $amount) {
            // Rounded to whole units on the job row, because ai_jobs.cost_charged
            // is an unsigned integer and the run rows carry the exact figures. The
            // job total is the operator's headline; the ledger is the evidence.
            $totals['spend.' . $unit] = (int) round($amount);
        }

        return $totals;
    }

    /** What the run spent in one unit, unrounded, for the job's own spend write. */
    public function spendIn(string $unit): ?float
    {
        return $this->spendByUnit[$unit] ?? null;
    }

    /**
     * The single unit this run actually spent in, or none when it spent in
     * several or in nothing.
     *
     * A run that touched two units has no one total, and answering with either
     * would be a number an operator would act on wrongly.
     */
    public function singleUnit(): ?string
    {
        if (\count($this->spendByUnit) !== 1) {
            return null;
        }

        return (string) array_key_first($this->spendByUnit);
    }

    public function log(): string
    {
        return implode("\n", array_map(static fn (ImportNote $note): string => $note->line(), $this->notes));
    }

    /**
     * Absorbs one unit's own notes into the job log, so a build's log reads as
     * one story rather than as twenty disconnected ones.
     */
    public function absorb(string $log): void
    {
        foreach (ImportNote::parseLog($log) as $note) {
            $this->notes[] = $note;
        }

        if ($this->onNote === null) {
            return;
        }

        ($this->onNote)($this);
    }
}
