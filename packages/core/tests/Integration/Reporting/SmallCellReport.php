<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportResult;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;

/**
 * A third-party aggregate whose cells are small enough to identify somebody.
 *
 * It exists to prove the property the pipeline claims: the small-cell policy is
 * one stage applied identically on screen and in the file, so a report that
 * does nothing about suppression itself is still suppressed on both paths. Two
 * of its three counts are below the default minimum of five, and the third is
 * what complementary suppression has to take with them.
 */
final class SmallCellReport implements TabularReportInterface
{
    public const KEY = 'small-cell-aggregate';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Small cell aggregate';
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
        return $this->describe($context)->facts;
    }

    public function describe(ReportContext $context): ReportResult
    {
        return ReportResult::of(['cohorts' => 1], [], 1);
    }

    public function columns(ReportContext $context): array
    {
        return [
            ColumnDefinition::of('cohort', 'Cohort'),
            ColumnDefinition::of('learners', 'Learners', ColumnDefinition::TYPE_COUNT),
            ColumnDefinition::of('completions', 'Completions', ColumnDefinition::TYPE_COUNT),
        ];
    }

    public function rows(ReportContext $context): iterable
    {
        // Two learners is below any sensible minimum, and six completions is
        // the next smallest count, which has to go with it or the two is
        // recoverable by subtraction.
        yield ['cohort' => 'Autumn intake', 'learners' => 2, 'completions' => 6];
    }
}
