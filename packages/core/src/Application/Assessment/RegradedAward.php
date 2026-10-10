<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Registry\QuestionType\GradeResult;

/**
 * One question's new award inside a regrade run.
 *
 * The grade result is null for the two policies that award by fiat rather
 * than by re-running a marking rule, which is why a full credit regrade is
 * safe on an item whose provider is no longer installed.
 */
final class RegradedAward
{
    public function __construct(
        public readonly float $award,
        public readonly float $fraction,
        public readonly bool $counts,
        public readonly ?GradeResult $result,
    ) {
    }
}
