<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Report;

/**
 * Every row of this report is one identifiable person.
 *
 * It is a marker and it is what the second delivery refusal reads. An aggregate
 * report crossing an organisation boundary in an email attachment is a
 * disclosure of numbers; a row-per-person report crossing the same boundary is a
 * disclosure of people, and the two are not the same risk.
 *
 * Default when absent: the report is treated as aggregate, so attachment
 * delivery is not refused on this ground. That direction is deliberate and is
 * the opposite of the suppression marker's: suppression is applied unless a
 * provider opts out, because forgetting it hides nothing; this is applied only
 * when a provider opts in, because treating every report as personal would
 * refuse the delivery of a seat count. A provider that gets this wrong is
 * declaring less than it knows, and the columns it declares are still filtered
 * by the disclosure policy and suppressed by the cell policy.
 */
interface RowPerPerson
{
}
