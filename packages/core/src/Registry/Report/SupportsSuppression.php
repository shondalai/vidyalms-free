<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Report;

use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSuppressionPolicy;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;

/**
 * The report suppresses its own small cells and the pipeline stands aside.
 *
 * Default when absent, which is every shipped report: small-cell suppression
 * is applied by the pipeline over the columns the definition declared, using
 * {@see CellSuppressionPolicy}. That is the direction on purpose. Suppression
 * implemented per report is suppression forgotten in the eleventh one, so a
 * provider has to opt out of the pipeline rather than opt in to it, and a
 * provider that opts out is saying it has a better answer for its own shape
 * rather than that it has no answer.
 */
interface SupportsSuppression
{
    /**
     * The policy this report wants applied, or null to suppress nothing
     * because the provider has already done it.
     */
    public function suppressionFor(ReportContext $context): ?CellSuppressionPolicy;
}
