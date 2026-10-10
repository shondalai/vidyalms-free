<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

/**
 * The sink an importer reports through while it runs. Everything an import
 * produces goes through here rather than being returned at the end, which is
 * what makes a long migration observable and a failed one honest: notes reach
 * the job log as they happen, and the totals counted before a failure survive
 * it.
 *
 * An importer holds no state of its own between calls; the run's state lives
 * on this object, which is why the job id and the dry run flag are read from
 * here and not from the provider.
 */
interface ImportProgress
{
    /**
     * The import job this run belongs to, or 0 when the importer is running
     * outside one. Pass it to ImportMap::remember() so the job's manifest is
     * complete.
     */
    public function jobId(): int;

    /**
     * True when the run must analyse only: count what it would create, note
     * what it would skip, and write nothing at all, neither through a use
     * case nor into the import map.
     */
    public function isDryRun(): bool;

    /**
     * Appends one note, which reaches the job log immediately.
     *
     * @param string $level one of the ImportNote level constants
     */
    public function note(string $level, string $message): void;

    /**
     * Adds to a running total. Use the ImportResult TOTAL_* keys for anything
     * an admin screen counts.
     */
    public function counted(string $what, int $delta = 1): void;
}
