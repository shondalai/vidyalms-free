<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Token;

/**
 * The output context a notification placeholder is safe to occupy.
 *
 * Text is the conservative default for providers that predate the
 * notification studio. A URL has to be declared explicitly before the
 * studio will offer it in an action or asset field.
 */
enum NotificationTokenType: string
{
    case Text = 'text';
    case Url = 'url';
}
