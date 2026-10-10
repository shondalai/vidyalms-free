<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

/**
 * The channel consumes body_short. Default when absent: body_text,
 * truncated by the channel.
 */
interface SupportsShortForm
{
}
