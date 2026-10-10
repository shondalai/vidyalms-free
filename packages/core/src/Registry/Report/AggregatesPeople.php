<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Report;

use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;

/**
 * The scalar half of this report counts people, and says which of its facts do.
 *
 * {@see SupportsSuppression} and {@see ColumnDefinition::count()} already carry
 * small-cell suppression across the tabular half, and a scalar report has no
 * columns at all: `org-overview` is six numbers and three of them are counts of
 * people. Without this, the suppression rule would apply to every row of every
 * table and to none of the summary blocks, which is precisely where per-report
 * suppression is forgotten, and a five-person organisation's summary is the
 * smallest cell in the product.
 *
 * **It is a capability interface over the shipped contract, never a changed
 * signature.** A report that does not declare it has its facts passed through,
 * which is right: a fact this build cannot tell apart from a course count must
 * not be blanked on suspicion, and a report whose facts are counts of people
 * says so in one method.
 */
interface AggregatesPeople
{
    /**
     * Which keys of {@see ReportResult::$facts} are counts of people or of
     * subjects, and therefore what small-cell suppression acts on.
     *
     * @return list<string>
     */
    public function countFactKeys(ReportContext $context): array;
}
