<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Registry\Context\FiltersByVisibility;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportResult;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;

/**
 * A report a package outside the core would register, standing in for one.
 *
 * It reads nothing and computes nothing on purpose. What it proves is the
 * plumbing: that a tabular report registered from a KernelReady listener is
 * served by the site report route, is exportable through the 202 route, is
 * written by a free format writer and gets a manifest row, with no file under
 * packages/core edited to make any of that happen.
 */
final class ThrowawayReport implements TabularReportInterface, FiltersByVisibility
{
    public const KEY = 'throwaway-widgets';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Throwaway widgets';
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
            'properties' => ['colour' => ['type' => 'string']],
        ];
    }

    public function generate(ReportContext $context): array
    {
        return $this->describe($context)->facts;
    }

    public function describe(ReportContext $context): ReportResult
    {
        return ReportResult::of(['widgets' => 3, 'colour' => $context->string('colour') ?? 'unpainted'], [], 3);
    }

    public function columns(ReportContext $context): array
    {
        return [
            ColumnDefinition::of('name', 'Name'),
            ColumnDefinition::count('made', 'Made'),
        ];
    }

    public function rows(ReportContext $context): iterable
    {
        yield ['name' => 'first', 'made' => 12];
        yield ['name' => 'second', 'made' => 34];
        yield ['name' => 'third', 'made' => 56];
    }
}
