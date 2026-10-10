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
 * The most recent counted attempt that has a result.
 *
 * By attempt number rather than by submitted_at, because a regrade moves a
 * grade without moving the attempt, and because an attempt submitted by the
 * sweeper after a later one was submitted by hand is still the earlier
 * attempt.
 */
final class LatestAggregationMethod implements AggregationMethodInterface
{
    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return AggregationMethodRegistry::LATEST;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.aggregation.latest');
    }

    public function aggregate(array $attempts, AggregationContext $context): ?AggregatedGrade
    {
        $latest = null;

        foreach ($attempts as $attempt) {
            if (!$attempt->isGraded()) {
                continue;
            }

            if ($latest === null || $attempt->attemptNumber >= $latest->attemptNumber) {
                $latest = $attempt;
            }
        }

        return $latest instanceof AttemptScore ? AggregatedGrade::from($latest) : null;
    }

    public function paramsSchema(): ?array
    {
        return null;
    }
}
