<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Aggregation;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * How a learner's counted attempts reduce to the one grade a quiz reports.
 *
 * Two orthogonal settings rather than one four-valued grading method, and
 * the composition order is fixed and stated: attempts_counted filters the
 * set, and this reduces what is left. So "first" or "last" counted makes the
 * aggregator a no-op by construction, and "counted: first" with
 * "aggregation: highest" is the first attempt and not the highest of all.
 *
 * The free build registers highest and latest. Average, first, lowest,
 * average of the best n and a decaying average are registrations, not
 * migrations, which is the whole reason this is a provider.
 */
interface AggregationMethodInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The reportable grade, or null when nothing in the set has one yet.
     *
     * @param list<AttemptScore> $attempts the counted set, oldest first
     */
    public function aggregate(array $attempts, AggregationContext $context): ?AggregatedGrade;

    /**
     * A JSON Schema fragment for the parameters this method takes, or null
     * when it takes none. The settings form renders it; nothing in the core
     * interprets it.
     *
     * @return array<string, mixed>|null
     */
    public function paramsSchema(): ?array;
}
