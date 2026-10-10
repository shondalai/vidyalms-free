<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * A migration run. Status only ever moves forward: pending, running, then one
 * of completed, completed_with_problems, previewed or failed.
 *
 * "previewed" is the terminal status of a dry run: the importer analysed the
 * source and reported what it would create, and nothing was written. A
 * previewed job is not an applied import, so re-running the same source for
 * real is still a first run.
 *
 * ## When the run dies without saying so
 *
 * An import runs in a task process, and a process can be killed: an OOM, a
 * deploy, a host that recycles its workers. The row it left behind still says
 * "running", and nothing will ever change it, so a screen that polls it polls
 * for ever.
 *
 * The job carries a heartbeat for that: every progress write touches
 * updatedAt, so a live run keeps moving it. hasStalled() reports a running job
 * whose heartbeat has not moved for STALE_AFTER_SECONDS as one that has
 * stopped responding. It is a presumption and it is reported as one: the
 * status stays "running", because flipping it to "failed" would claim the
 * importer reported an error it never reported, and a slow run that writes
 * again stops being stale on its own.
 */
final class ImportJob
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PREVIEWED = 'previewed';
    public const STATUS_FAILED = 'failed';

    /**
     * Finished, and not cleanly.
     *
     * Canvas distinguishes `imported` from `imported_with_messages` for a
     * reason an operator feels three weeks later: a green job whose four
     * hundred bad rows are buried in a two hundred line log is a job that
     * reports success and did not have it. This status is reached from
     * ImportResult::hasErrors(), which already computed the predicate and had
     * nowhere to put it.
     *
     * It is distinct from "failed", which means nothing was written at all.
     * A run that created nine hundred of a thousand rows is neither
     * "completed" nor "failed", and calling it either loses the fact an
     * operator has to act on.
     */
    public const STATUS_COMPLETED_WITH_PROBLEMS = 'completed_with_problems';

    /**
     * Every status a run can end on. One list, because a reader that has to
     * enumerate them itself is a reader that will be missing the next one:
     * completed_with_problems was added to this vocabulary and the terminal
     * test was a literal array in two files.
     *
     * @var list<string>
     */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_COMPLETED_WITH_PROBLEMS,
        self::STATUS_PREVIEWED,
        self::STATUS_FAILED,
    ];

    /**
     * How long a running job may go without a heartbeat before it is reported
     * as stopped responding. The runner writes progress on every note of the
     * first fifty and every tenth note after that, so a quarter of an hour of
     * silence is a run that is no longer running.
     */
    public const STALE_AFTER_SECONDS = 900;

    /**
     * @param array<string, mixed>      $options
     * @param array<string, mixed>|null $totals
     */
    public function __construct(
        public readonly int $id,
        public readonly string $importer,
        public readonly string $status,
        public readonly ?string $sourcePath,
        public readonly array $options,
        public readonly ?string $log,
        public readonly ?array $totals,
        public readonly ?\DateTimeImmutable $startedAt,
        public readonly ?\DateTimeImmutable $finishedAt,
        public readonly int $createdBy,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
        /**
         * The organisation whose roster this run loaded, or null for a
         * site-scoped migration.
         *
         * Stamped from the run context and never from a column in the file.
         * Null is not an absent tenant: it is the site, which is what every
         * job written before this column existed is and what every migration
         * an operator starts from the site console still is.
         */
        public readonly ?int $organisationId = null,
        /**
         * The shape the importer's options were written in.
         *
         * import_jobs.options is provider-owned JSON and RunQueuedImport reads
         * it back at drain time, which may be days later and after a plugin
         * upgrade: a replaced importer used to read a queued job's options as
         * though it had written them. The version travels in a StoredShape
         * envelope that never leaves the repository, so options is the body
         * alone here and everywhere downstream.
         *
         * Zero is a job written before the envelope existed, which is every
         * row both development sites already carry.
         */
        public readonly int $optionsVersion = 0,
        public readonly ?int $organisationUnitId = null,
    ) {
    }

    /** Whether this run belongs to one organisation rather than to the site. */
    public function belongsToOrganisation(int $organisationId): bool
    {
        return $this->organisationId !== null && $this->organisationId === $organisationId;
    }

    public function isDryRun(): bool
    {
        return ($this->options['dry_run'] ?? false) === true;
    }

    public function isFinished(): bool
    {
        return \in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * The last time this run wrote anything: its own progress while it runs,
     * and the write that finished it once it has.
     */
    public function heartbeatAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt ?? $this->startedAt ?? $this->createdAt;
    }

    public function hasStalled(\DateTimeImmutable $now, int $afterSeconds = self::STALE_AFTER_SECONDS): bool
    {
        if ($this->status !== self::STATUS_RUNNING) {
            return false;
        }

        $heartbeat = $this->heartbeatAt();

        return $heartbeat !== null && $now->getTimestamp() - $heartbeat->getTimestamp() > $afterSeconds;
    }
}
