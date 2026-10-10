<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

/**
 * One failed-rows download: the CSV body, and what the caller has to be told
 * about it that the body alone cannot say.
 *
 * The body used to be returned as a bare string, and the cap was therefore
 * invisible twice over: the file ended at row ten thousand and one with no
 * marker, and the response carried no header saying a cap had been reached. An
 * operator with twelve thousand rejected rows fixed ten thousand of them, ran
 * the source again, and never learned that two thousand rows had never been in
 * the file they were working from.
 *
 * So the two facts travel with the bytes. The body carries a trailing marker
 * row for the person who opens it in a spreadsheet; the headers carry the same
 * two numbers for the client that never opens it at all. report_runs already
 * carries a truncation flag for exactly this reason, and this is the same fact
 * on the download that has no run record.
 */
final class FailedRowsDownload
{
    private function __construct(
        /** The CSV, BOM and all, already neutralised cell by cell. */
        public readonly string $body,
        /** How many rows the run rejected altogether. */
        public readonly int $rejected,
        /** How many of them this body carries. */
        public readonly int $rendered,
        /** The cap the body was read under. */
        public readonly int $limit,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $problems RowProblem::toArray() shape
     */
    public static function of(array $problems, int $rejected, int $limit): self
    {
        $rendered = \count($problems);

        return new self(
            FailedRowsFile::render($problems, $rejected, $limit),
            max($rejected, $rendered),
            $rendered,
            max(0, $limit),
        );
    }

    /** Whether rejected rows were left out of the body. */
    public function wasTruncated(): bool
    {
        return FailedRowsFile::wasTruncated($this->rendered, $this->rejected, $this->limit);
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return FailedRowsFile::headersFor($this->rendered, $this->rejected, $this->limit);
    }
}
