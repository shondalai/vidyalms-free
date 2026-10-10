<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduledTask;

use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Report\ReportRunStore;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\RunsPeriodically;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskHandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\ScheduledTask\ScheduledTaskRun;

/**
 * Deletes report artefacts past their retention date.
 *
 * It exists because `GenerateReportTask` used to write an untracked blob into
 * object storage, so an erasure request had nothing to find: the file was named
 * after a queue row that was long gone and no table said it existed. GDPR
 * Article 5(1)(e) binds these files as much as any other personal data, and a
 * retention policy nothing enforces is a retention policy nobody has.
 *
 * **The manifest row survives the artefact**, marked expired. That is
 * deliberate: the row is the evidence that a report was run, by whom and under
 * which scope, and destroying it along with the file would delete the audit
 * trail to satisfy the retention rule the audit trail exists to demonstrate.
 * The row itself leaves through the privacy contributor, on a subject request.
 *
 * It asserts what the schema promises. The column is non-null, so a run with no
 * expiry cannot exist; the assertion is here because the whole reason the
 * column is non-null is that a nullable one is skipped silently for the life of
 * the site, and an invariant nothing checks is a comment.
 */
final class SweepReportRunsTask implements ScheduledTaskHandlerInterface, RunsPeriodically
{
    public const KEY = 'reports.sweep';

    /** One batch per run. Bounded, because a sweep that deletes ten thousand files in one turn times out. */
    private const BATCH = 100;

    public function __construct(
        private readonly ReportRunStore $runs,
        private readonly StoragePort $storage,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function run(ScheduledTaskRun $run): string
    {
        $unexpiring = $this->runs->withoutExpiry();
        $removed = 0;
        $missing = 0;

        foreach ($this->runs->dueForSweep(self::BATCH) as $due) {
            $id = $due->id;

            if ($id === null) {
                continue;
            }

            if ($due->storagePath !== null) {
                if ($this->storage->exists($due->storagePath)) {
                    $this->storage->delete($due->storagePath);
                    $removed++;
                } else {
                    // Already gone, which is the normal state for a site whose
                    // storage was pruned by hand. The row still has to move,
                    // or the sweep reads it again for ever.
                    $missing++;
                }
            }

            $this->runs->markExpired($id);
        }

        return sprintf(
            'reports.sweep removed=%d already_gone=%d without_expiry=%d',
            $removed,
            $missing,
            $unexpiring,
        );
    }

    public function binCount(): int
    {
        // One bin. The work is a bounded batch of deletes against a single
        // indexed predicate, so sharding it would buy nothing and make two
        // runs contend for the same rows.
        return 1;
    }

    public function periodBucket(\DateTimeImmutable $now): string
    {
        return $now->format('Y-m-d-H');
    }

    public function periodicDedupeKey(string $periodBucket, int $binIndex): string
    {
        return 'reports:' . ReportRun::STATE_EXPIRED . ':' . $periodBucket . ':' . $binIndex;
    }
}
