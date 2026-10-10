<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

/**
 * A type whose xAPI interaction token depends on whether the item was placed
 * as scored.
 *
 * ReportingMetadataInterface::xapiInteractionType() takes no arguments and
 * cannot, because it answers for the type rather than for a placement. One
 * type in the design needs both answers: a scale reports as "numeric" when
 * it is marked and as "likert" when it is an opinion item, and "scored" is a
 * questions column rather than a definition field, so the type cannot read
 * it from the definition either.
 *
 * A narrow interface rather than a second method on a shipped one, for the
 * reason the design gives for every optional behaviour: QuestionTypeInterface
 * and its companions are shipped bytes that third parties implement, and a
 * method added to one breaks every external type.
 *
 * Default when absent: xapiInteractionType(), for both placements.
 */
interface ScoredReportingInterface
{
    /**
     * @param bool $scored whether the placement counts towards the attempt
     *                     totals, from the frozen manifest and never from
     *                     the live question row
     *
     * @return string one of the ten fixed xAPI interaction tokens
     */
    public function xapiInteractionTypeWhen(bool $scored): string;
}
