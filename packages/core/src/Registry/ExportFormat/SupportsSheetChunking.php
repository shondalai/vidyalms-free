<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ExportFormat;

/**
 * The format can spread a result across several sheets.
 *
 * Default when absent: over the sheet row limit the run **fails with a stated
 * reason** rather than truncating. Silently truncating an export is worse than
 * refusing it, because the recipient cannot tell that rows are missing and a
 * figure computed from the file is then wrong with no signal anywhere.
 */
interface SupportsSheetChunking
{
    /** How many rows this format puts in one sheet. */
    public function rowsPerSheet(): int;
}
