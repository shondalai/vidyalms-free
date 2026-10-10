<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

interface ActivityLogRepository
{
    /**
     * Appends one entry. Entries carrying an event reference are idempotent:
     * appending the same occurrence twice keeps a single row.
     */
    public function append(ActivityEntry $entry): void;
}
