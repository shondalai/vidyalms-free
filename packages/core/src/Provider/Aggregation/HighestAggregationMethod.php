<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Aggregation;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregatedGrade;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationContext;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodInterface;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodRegistry;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AttemptScore;

/**
 * The best counted attempt, which is the default and what the free build has
 * always reported as best_percentage.
 *
 * Ties go to the earlier attempt: two attempts at the same percentage are
 * the same grade, and picking the later one would make a resit that changed
 * nothing look like the graded evidence.
 */
final class HighestAggregationMethod implements AggregationMethodInterface
{
    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return AggregationMethodRegistry::HIGHEST;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.aggregation.highest');
    }

    public function aggregate(array $attempts, AggregationContext $context): ?AggregatedGrade
    {
        $best = null;

        foreach ($attempts as $attempt) {
            if (!$attempt->isGraded()) {
                continue;
            }

            if ($best === null || (float) $attempt->percentage > (float) $best->percentage) {
                $best = $attempt;
            }
        }

        return $best instanceof AttemptScore ? AggregatedGrade::from($best) : null;
    }

    public function paramsSchema(): ?array
    {
        return null;
    }
}
