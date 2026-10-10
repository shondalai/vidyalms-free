<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

/**
 * The channel's quiet-hours window is law rather than courtesy, so no
 * per-rule override may defeat it and the channel refuses to send when no
 * per-recipient timezone is available rather than guessing.
 *
 * A refusal is recoverable; a three in the morning text is not. Default when
 * absent: the window is a courtesy and the site timezone is an acceptable
 * fallback.
 */
interface StatutoryQuietHours
{
    /**
     * @return array{start: string, end: string} local wall-clock bounds, "HH:MM"
     */
    public function statutoryWindow(): array;
}
