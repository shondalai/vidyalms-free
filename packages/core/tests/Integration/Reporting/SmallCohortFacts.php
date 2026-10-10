<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Report\AggregatesPeople;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportDefinitionInterface;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportResult;

/**
 * A third-party SCALAR report whose facts are counts of people.
 *
 * It has no rows at all, which is the whole point: the site read applied
 * small-cell suppression to rows and to nothing else, so a summary block of
 * three completions out of twelve entitled learners was served with the three
 * in it. A summary is exactly as disclosing as a row and is exactly where
 * per-report suppression is forgotten.
 */
final class SmallCohortFacts implements ReportDefinitionInterface, AggregatesPeople, FiltersByVisibility
{
    public const KEY = 'small-cohort-facts';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Small cohort facts';
    }

    public function requiredCapability(): string
    {
        return Capability::VIEW_REPORTS;
    }

    public function parameterSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function generate(ReportContext $context): array
    {
        return $this->describe($context)->toArray();
    }

    public function describe(ReportContext $context): ReportResult
    {
        return ReportResult::of(['entitled' => 12, 'completed' => 3, 'courses' => 4]);
    }

    /** @return list<string> */
    public function countFactKeys(ReportContext $context): array
    {
        return ['entitled', 'completed'];
    }
}
