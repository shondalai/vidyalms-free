<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

/**
 * The channel accepts storage references. Default when absent: attachments
 * are dropped and the send is logged as degraded, which is what the degraded
 * flag on SendResult exists to record.
 */
interface SupportsAttachments
{
}
