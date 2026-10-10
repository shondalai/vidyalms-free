<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Reporting;

use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportContext;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportFormatInterface;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\StreamWriter;

/**
 * An export format a package outside the core would register, standing in for
 * `xlsx` and `pdf`.
 *
 * It exists so the catalogue's promise can be tested rather than asserted: a
 * format nothing in `packages/core` names appears in the picker the moment it
 * registers, which is what makes a fourth format a registration rather than an
 * edit to a screen.
 */
final class ThrowawayExportFormat implements ExportFormatInterface
{
    public const KEY = 'throwaway-tsv';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Throwaway TSV';
    }

    public function group(): string
    {
        return 'spreadsheet';
    }

    public function order(): int
    {
        return 50;
    }

    public function extension(): string
    {
        return 'tsv';
    }

    public function mimeType(): string
    {
        return 'text/tab-separated-values';
    }

    public function write(iterable $rows, array $columns, ExportContext $context, StreamWriter $out): void
    {
        foreach ($rows as $row) {
            $out->write(implode("\t", array_map(
                static fn (mixed $value): string => \is_scalar($value) ? (string) $value : '',
                $row,
            )) . "\n");
        }
    }
}
