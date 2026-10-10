<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ExportFormat;

use Shondalai\VidyaLMS\Core\Domain\Reporting\CellSafetyPolicy;

/**
 * What an export writer is handed beside the rows.
 *
 * A context object rather than six parameters, on the house rule: a later
 * field is additive and no registered writer breaks, while adding a parameter
 * to a shipped interface breaks every third-party implementation.
 */
final class ExportContext
{
    /**
     * Canonical values: RFC 3339 instants with an offset, a dot decimal
     * separator, no thousands separators, and money as integer minor units
     * with an ISO 4217 code in a sibling column.
     */
    public const PROFILE_MACHINE = 'machine';

    /** Locale-formatted values, for a person opening the file. */
    public const PROFILE_DISPLAY = 'display';

    /**
     * Excel caps a worksheet at 1,048,576 rows. Over it, a format declaring
     * {@see SupportsSheetChunking} chunks; everything else fails the run with a
     * stated reason, because silently truncating an export is worse than
     * refusing it: the recipient cannot tell.
     */
    public const SHEET_ROW_LIMIT = 1048576;

    private function __construct(
        public readonly string $valueProfile,
        public readonly CellSafetyPolicy $cellSafety,
        /**
         * Excel cannot reliably detect UTF-8 in a .csv without EF BB BF, while
         * a strict UTF-8 pipeline reads the same bytes as first-field
         * corruption. So it is a per-request option and never a constant.
         */
        public readonly bool $byteOrderMark,
        public readonly int $sheetRowLimit,
        public readonly string $locale,
        public readonly ?int $runId,
    ) {
    }

    public static function create(
        ?CellSafetyPolicy $cellSafety = null,
        string $valueProfile = self::PROFILE_MACHINE,
        bool $byteOrderMark = true,
        int $sheetRowLimit = self::SHEET_ROW_LIMIT,
        string $locale = 'en-GB',
        ?int $runId = null,
    ): self {
        if ($valueProfile !== self::PROFILE_MACHINE && $valueProfile !== self::PROFILE_DISPLAY) {
            throw new \InvalidArgumentException('An export value profile is "machine" or "display".');
        }

        return new self(
            $valueProfile,
            $cellSafety ?? CellSafetyPolicy::spreadsheetSafe(),
            $byteOrderMark,
            max(1, $sheetRowLimit),
            $locale,
            $runId,
        );
    }

    public function isMachineProfile(): bool
    {
        return $this->valueProfile === self::PROFILE_MACHINE;
    }
}
