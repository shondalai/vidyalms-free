<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

/**
 * The channel accepts the List-* and Auto-Submitted header map. Default when
 * absent: headers are dropped, so the channel may not carry a
 * non-transactional class at all, because the RFC 8058 pair would go with
 * them.
 */
interface SupportsListHeaders
{
}
