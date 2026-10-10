<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Report;

/**
 * What a report definition answers.
 *
 * It is a value object rather than the loose array the shipped
 * {@see ReportProviderInterface::generate()} returns, because three separate
 * callers need to tell one answer apart from another and an array cannot say
 * which is which: the 202 export contract has to distinguish a run that
 * produced zero rows from one that failed, the screen has to render a fact
 * the provider computed beside the rows it selected, and the manifest has to
 * record a row count and a truncation flag that nothing in an untyped payload
 * carried.
 *
 * `facts` are the scalars a report computes about the whole run, `notes` are
 * the things it wants a reader to know (the chain verified, a provider was
 * missing, a cell was suppressed), and both survive into the export.
 */
final class ReportResult
{
    /**
     * @param array<string, mixed> $facts
     * @param list<string>         $notes
     */
    private function __construct(
        public readonly array $facts,
        public readonly array $notes,
        public readonly int $rowCount,
        public readonly bool $truncated,
    ) {
    }

    /**
     * @param array<string, mixed> $facts
     * @param list<string>         $notes
     */
    public static function of(array $facts, array $notes = [], int $rowCount = 0, bool $truncated = false): self
    {
        return new self($facts, $notes, max(0, $rowCount), $truncated);
    }

    /** @param list<string> $notes */
    public function withNotes(array $notes): self
    {
        return new self($this->facts, array_values(array_unique([...$this->notes, ...$notes])), $this->rowCount, $this->truncated);
    }

    public function withRowCount(int $rowCount, bool $truncated = false): self
    {
        return new self($this->facts, $this->notes, max(0, $rowCount), $truncated || $this->truncated);
    }

    /**
     * The shape the shipped legacy path already answers with, so a definition
     * can implement describe() once and let generate() delegate.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->facts + [
            'row_count' => $this->rowCount,
            'truncated' => $this->truncated,
            'notes' => $this->notes,
        ];
    }
}
