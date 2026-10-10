<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * Ends a run that stopped responding, because an operator said to.
 *
 * Nothing marks a job failed on the job's own behalf. "Stalled" is what a
 * reader presumes about a running row whose heartbeat stopped moving, and a
 * slow run that writes again stops being stale by itself, so the core never
 * rewrites the status on a presumption. This use case is the other half of
 * that arrangement: a person who has looked at the row decides, and the log
 * records that the decision was theirs rather than something the importer
 * reported.
 *
 * A job that is still moving is refused. A screen left open for an hour must
 * not be able to end a run that recovered while it sat there, and the check
 * is made here rather than on the screen because the screen's idea of "now"
 * is as old as its last poll.
 *
 * Nothing is rolled back, as everywhere else in the import path: what the run
 * created before it died is still there, and running the same source again is
 * the recovery.
 */
final class AbandonImportJob
{
    public const NOTE = 'The run stopped responding and was marked failed by an operator.'
        . ' Anything it created before it stopped is still there: run the same source again to continue it.';

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
    ) {
    }

    public function execute(Principal $principal, int $jobId): ImportJob
    {
        $this->guard->require($principal, Capability::MANAGE);

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $job = $this->jobs->find($jobId) ?? throw EntityNotFoundException::for('Import job', $jobId);

        if ($job->isFinished()) {
            throw new ConflictException(sprintf(
                'Import job %d has already finished as %s.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $jobId,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $job->status,
            ));
        }

        if (!$job->hasStalled($this->clock->now())) {
            throw new ConflictException(sprintf(
                'Import job %d is still writing, so it was left alone.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $jobId,
            ));
        }

        $notes = ImportNote::parseLog($job->log);
        $notes[] = ImportNote::error(self::NOTE);

        $this->jobs->markFailed(
            $jobId,
            implode("\n", array_map(static fn (ImportNote $note): string => $note->line(), $notes)),
            $job->totals === null ? [] : self::counts($job->totals),
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        return $this->jobs->find($jobId) ?? throw EntityNotFoundException::for('Import job', $jobId);
    }

    /**
     * The totals as they were when the run stopped. They are kept rather than
     * cleared: those rows exist.
     *
     * @param array<string, mixed> $totals
     *
     * @return array<string, int>
     */
    private static function counts(array $totals): array
    {
        $counts = [];

        /** @var mixed $count */
        foreach ($totals as $what => $count) {
            if (is_numeric($count)) {
                $counts[$what] = (int) $count;
            }
        }

        return $counts;
    }
}
