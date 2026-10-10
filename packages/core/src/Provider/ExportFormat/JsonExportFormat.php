<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ExportFormat;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportContext;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\ExportFormatInterface;
use Shondalai\VidyaLMS\Core\Registry\ExportFormat\StreamWriter;
use Shondalai\VidyaLMS\Core\Registry\Report\ColumnDefinition;

/**
 * A streamed JSON document: the column descriptions, then the rows.
 *
 * It writes the array element by element rather than encoding the whole result,
 * for the same reason the CSV writer streams: a gradebook assembled in memory
 * is the failure mode this seam exists to avoid.
 *
 * No cell safety policy is applied and none is needed. The injection this
 * product guards against is a spreadsheet evaluating a leading `=`, and JSON
 * is not a spreadsheet; neutralising values here would corrupt them for the
 * pipeline that asked for JSON precisely because it wanted them unaltered.
 */
final class JsonExportFormat implements ExportFormatInterface
{
    public const KEY = 'json';

    public function __construct(private readonly TranslatorPort $translator)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.export.json');
    }

    public function group(): string
    {
        return 'data';
    }

    public function order(): int
    {
        return 20;
    }

    public function extension(): string
    {
        return 'json';
    }

    public function mimeType(): string
    {
        return 'application/json';
    }

    public function write(iterable $rows, array $columns, ExportContext $context, StreamWriter $out): void
    {
        $header = [
            'columns' => array_map(
                static fn (ColumnDefinition $column): array => $column->toArray(),
                $columns,
            ),
            'value_profile' => $context->valueProfile,
            'run_id' => $context->runId,
        ];

        $out->write('{"meta":' . self::encode($header) . ',"items":[');
        $first = true;

        foreach ($rows as $row) {
            $projected = [];

            foreach ($columns as $column) {
                $projected[$column->key] = self::value($row[$column->key] ?? null);
            }

            $out->write(($first ? '' : ',') . self::encode($projected));
            $first = false;
        }

        $out->write(']}');
    }

    private static function value(mixed $value): mixed
    {
        return $value instanceof \DateTimeInterface ? $value->format(DATE_ATOM) : $value;
    }

    /** @param array<string, mixed> $data */
    private static function encode(array $data): string
    {
        return (string) json_encode($data, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
    }
}
