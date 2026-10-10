<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer;

use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportResult;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Registry\Importer\ImportProgress;

/**
 * The state of one Community Quiz run: the source it reads, who it runs as,
 * where its notes go, and the tallies it can only report once it has seen
 * everything.
 *
 * It exists because the importer itself holds no state between calls, and
 * because two kinds of counting have to be kept apart. Every skipped row is
 * counted individually in ImportResult::TOTAL_SKIPPED, so the totals are
 * honest; the notes that explain them are aggregated per type, so a quiz with
 * two hundred hotspot questions writes one line in the log rather than two
 * hundred.
 */
final class CommunityQuizRun
{
    /** @var array<string, int> */
    private array $tallies = [];

    /** @var array<int, int> Community Quiz course id to the local course id */
    private array $courses = [];

    public function __construct(
        public readonly CommunityQuizSource $source,
        public readonly Principal $principal,
        public readonly ImportProgress $progress,
        public readonly bool $publish,
        public readonly bool $enrolments,
    ) {
    }

    public function isDryRun(): bool
    {
        return $this->progress->isDryRun();
    }

    public function jobId(): int
    {
        return $this->progress->jobId();
    }

    public function note(string $message): void
    {
        $this->progress->note(ImportNote::INFO, $message);
    }

    public function warn(string $message): void
    {
        $this->progress->note(ImportNote::WARNING, $message);
    }

    /**
     * Something the importer has no mapping for went wrong and cost a whole
     * source object. It is an error rather than a warning because nobody
     * chose it, and it is counted as skipped because a row was lost.
     */
    public function failed(string $message): void
    {
        $this->progress->note(ImportNote::ERROR, $message);
        $this->counted(ImportResult::TOTAL_SKIPPED);
    }

    public function counted(string $what, int $delta = 1): void
    {
        $this->progress->counted($what, $delta);
    }

    /**
     * One row the importer deliberately did not bring in, explained in the
     * log and counted in the totals.
     */
    public function skipped(string $message): void
    {
        $this->warn($message);
        $this->counted(ImportResult::TOTAL_SKIPPED);
    }

    /**
     * One row deliberately not brought in whose explanation is deferred to
     * the run's closing summary, because it is the same explanation for every
     * row of its kind.
     */
    public function skippedQuietly(string $tally, int $delta = 1): void
    {
        $this->tally($tally, $delta);
        $this->counted(ImportResult::TOTAL_SKIPPED, $delta);
    }

    public function tally(string $what, int $delta = 1): void
    {
        $this->tallies[$what] = ($this->tallies[$what] ?? 0) + $delta;
    }

    public function tallied(string $what): int
    {
        return $this->tallies[$what] ?? 0;
    }

    /**
     * Every tally whose name starts with the prefix, keyed by the remainder
     * of the name: tallies("question:") answers "which question types were
     * left behind, and how many of each".
     *
     * @return array<string, int>
     */
    public function tallies(string $prefix): array
    {
        $matched = [];

        foreach ($this->tallies as $name => $count) {
            if (str_starts_with($name, $prefix)) {
                $matched[substr($name, \strlen($prefix))] = $count;
            }
        }

        ksort($matched);

        return $matched;
    }

    public function rememberCourse(int $sourceId, int $localId): void
    {
        if ($localId > 0) {
            $this->courses[$sourceId] = $localId;
        }
    }

    public function courseFor(int $sourceId): ?int
    {
        return $this->courses[$sourceId] ?? null;
    }

    /**
     * @return array<int, int>
     */
    public function importedCourses(): array
    {
        return $this->courses;
    }
}
