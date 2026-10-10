<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\RosterMapper;

use Shondalai\VidyaLMS\Core\Application\Platform\RowProblem;
use Shondalai\VidyaLMS\Core\Domain\Roster\CourseAssignmentRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\OrgMembershipRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\OrgRecord;
use Shondalai\VidyaLMS\Core\Domain\Roster\PersonRecord;

/**
 * One line of a roster, mapped: either a canonical record, or the row problem
 * that says why it is not one.
 *
 * The mapper answers these and the importer writes them, which is the whole of
 * the split. A mapper that threw on a bad row would stop a forty thousand row
 * file on row nine, and a mapper that skipped one silently would leave the
 * operator with a roster that loaded cleanly and is missing four hundred
 * people. So a bad row is a value like any other, carrying the file, the
 * one-based line including the header, the column and one of the eight
 * published codes, and the importer records it against the job exactly as it
 * records a row it could not write.
 */
final class RosterRow
{
    private function __construct(
        public readonly string $file,
        public readonly int $line,
        public readonly ?object $record,
        public readonly ?RowProblem $problem,
        public readonly string $mode,
        /**
         * A problem the row carries without being rejected for it.
         *
         * The row is still written; the operator is still told. It is what a
         * mapper reports when the trade a row made is legitimate and worth
         * naming, such as a key derived from an address because the file
         * carried no external id.
         */
        public readonly ?RowProblem $warning = null,
    ) {
    }

    public static function of(
        string $file,
        int $line,
        object $record,
        string $mode = '',
        ?RowProblem $warning = null,
    ): self {
        return new self($file, $line, $record, null, $mode, $warning);
    }

    public static function rejected(string $file, int $line, RowProblem $problem, string $mode = ''): self
    {
        return new self($file, $line, null, $problem, $mode);
    }

    public function isRejected(): bool
    {
        return $this->problem !== null;
    }

    /** The record, for a caller that has already asked isRejected(). */
    public function requireRecord(): object
    {
        return $this->record ?? throw new \LogicException(
            'A rejected roster row carries a row problem and no record; report the problem instead.',
        );
    }

    public function person(): ?PersonRecord
    {
        return $this->record instanceof PersonRecord ? $this->record : null;
    }

    public function organisation(): ?OrgRecord
    {
        return $this->record instanceof OrgRecord ? $this->record : null;
    }

    public function membership(): ?OrgMembershipRecord
    {
        return $this->record instanceof OrgMembershipRecord ? $this->record : null;
    }

    public function assignment(): ?CourseAssignmentRecord
    {
        return $this->record instanceof CourseAssignmentRecord ? $this->record : null;
    }
}
