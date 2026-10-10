<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportRowProblemPage;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportRowProblemRepository;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * import_row_problems, appended as a run goes and read a page at a time.
 *
 * Two things here are load bearing rather than incidental. The first is that
 * append() writes and never reads: a run that has to ask whether it has
 * already reported a row pays a select per bad row, and a file whose every row
 * is bad is then quadratic in exactly the way the separate table exists to
 * avoid. The second is that the code histogram is one grouped aggregate rather
 * than a scan: "the failures are all unmapped_role" is the single most useful
 * sentence a support engineer can be handed, and computing it in PHP would
 * mean reading forty thousand rows to print eight numbers.
 */
final class DatabaseImportRowProblemRepository implements ImportRowProblemRepository
{
    /** The most rows one failed-rows download reconstructs. */
    public const MAX_REJECTED = 10000;

    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function append(int $jobId, array $problems): void
    {
        if ($jobId < 1 || $problems === []) {
            return;
        }

        foreach ($problems as $problem) {
            $row = RowProblem::fromArray($problem);

            $this->database->insert('import_row_problems', [
                'import_job_id' => $jobId,
                'file' => $row->file,
                'row' => $row->row,
                'column_name' => $row->column,
                'code' => $row->code,
                'message' => $row->message,
                'value_excerpt' => $row->value === '' ? null : $row->value,
                'outcome' => $row->outcome,
            ]);
        }
    }

    public function page(
        int $jobId,
        int $page,
        int $perPage,
        ?string $code = null,
        ?string $outcome = null,
    ): ImportRowProblemPage {
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        if ($jobId < 1) {
            return new ImportRowProblemPage([], 0, $page, $perPage);
        }

        $total = $this->database->count($this->narrowed($jobId, $code, $outcome));

        $rows = $this->database->selectAll(
            $this->narrowed($jobId, $code, $outcome)
                ->orderBy('file', Query::SORT_ASC)
                ->orderBy('row', Query::SORT_ASC)
                ->orderBy('id', Query::SORT_ASC)
                ->limit($perPage)
                ->offset(($page - 1) * $perPage),
        );

        return new ImportRowProblemPage(
            array_map($this->map(...), $rows),
            $total,
            $page,
            $perPage,
            $this->byCode($jobId),
            $this->database->count(
                Query::from('import_row_problems')
                    ->where('import_job_id', '=', $jobId)
                    ->whereIn('outcome', [RowProblem::OUTCOME_SKIPPED, RowProblem::OUTCOME_FAILED]),
            ),
        );
    }

    public function rejected(int $jobId, int $limit): array
    {
        if ($jobId < 1) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('import_row_problems')
                ->where('import_job_id', '=', $jobId)
                ->whereIn('outcome', [RowProblem::OUTCOME_SKIPPED, RowProblem::OUTCOME_FAILED])
                ->orderBy('file', Query::SORT_ASC)
                ->orderBy('row', Query::SORT_ASC)
                ->orderBy('id', Query::SORT_ASC)
                ->limit(min(self::MAX_REJECTED, max(1, $limit))),
        );

        return array_map($this->map(...), $rows);
    }

    public function forgetJob(int $jobId): void
    {
        if ($jobId < 1) {
            return;
        }

        $this->database->delete('import_row_problems', ['import_job_id' => $jobId]);
    }

    private function narrowed(int $jobId, ?string $code, ?string $outcome): Query
    {
        $query = Query::from('import_row_problems')->where('import_job_id', '=', $jobId);

        if ($code !== null && \in_array($code, RowProblem::CODES, true)) {
            $query->where('code', '=', $code);
        }

        if ($outcome !== null && \in_array($outcome, RowProblem::OUTCOMES, true)) {
            $query->where('outcome', '=', $outcome);
        }

        return $query;
    }

    /**
     * @return array<string, int>
     */
    private function byCode(int $jobId): array
    {
        $rows = $this->database->selectAll(
            Query::from('import_row_problems')
                ->select(['code'])
                ->aggregate(Aggregate::count('rows'))
                ->where('import_job_id', '=', $jobId)
                ->groupBy('code'),
        );

        $counts = [];

        foreach ($rows as $row) {
            $code = $row['code'] ?? null;

            if (\is_string($code) && $code !== '') {
                $counts[$code] = (int) ($row['rows'] ?? 0);
            }
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function map(array $row): array
    {
        return RowProblem::fromArray([
            'file' => \is_string($row['file'] ?? null) ? $row['file'] : '',
            'row' => (int) ($row['row'] ?? 1),
            'column' => \is_string($row['column_name'] ?? null) ? $row['column_name'] : null,
            'code' => \is_string($row['code'] ?? null) ? $row['code'] : '',
            'message' => \is_string($row['message'] ?? null) ? $row['message'] : '',
            'value' => \is_string($row['value_excerpt'] ?? null) ? $row['value_excerpt'] : '',
            'outcome' => \is_string($row['outcome'] ?? null) ? $row['outcome'] : '',
        ])->toArray();
    }
}
