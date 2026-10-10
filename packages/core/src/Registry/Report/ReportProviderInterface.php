<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Report;

use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * A report. The free build ships the course overview (enrolled, completed,
 * average score); Pro adds the gradebook, learner timelines and item
 * analysis. Heavy reports are computed by scheduled jobs, never on page
 * load; generate() may therefore return a pointer to a prepared result.
 *
 * It takes a context object rather than a loose parameter array and a bare
 * principal, because a loose array has nowhere to carry the visibility scope
 * a group leader's report needs and every report would otherwise dig it out
 * of the bag by a convention nobody published.
 */
interface ReportProviderInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The capability a caller must hold to run this report.
     */
    public function requiredCapability(): string;

    /**
     * @return array<string, mixed>
     */
    public function generate(ReportContext $context): array;
}
