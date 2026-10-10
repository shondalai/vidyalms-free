<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportResult;
use Shondalai\VidyaLMS\Core\Registry\Report\TabularReportInterface;

/**
 * A third-party report that does **not** declare FiltersByVisibility.
 *
 * It is deliberately identical to {@see ThrowawayReport} apart from the
 * missing marker, because the difference the marker makes is the whole point:
 * FiltersByVisibility's published default is that a provider which does not
 * declare it is site-wide by definition and a scoped run refuses it. Its rows
 * ignore the scope entirely, which is exactly what an unmarked provider is
 * assumed to do and why it may not be handed a narrowed run.
 */
final class SiteWideOnlyReport implements TabularReportInterface
{
    public const KEY = 'site-wide-only';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Site wide only';
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
        return ReportResult::of(['everything' => 2], [], 2);
    }

    public function columns(ReportContext $context): array
    {
        return [ColumnDefinition::of('secret', 'Secret')];
    }

    public function rows(ReportContext $context): iterable
    {
        yield ['secret' => 'every course on the site'];
        yield ['secret' => 'including the ones you do not teach'];
    }
}
