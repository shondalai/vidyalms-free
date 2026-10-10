<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Users;

/**
 * The recipient facts the core cannot otherwise know: a person's timezone and
 * their locale.
 *
 * One optional, defaulted port member rather than two, because the gap is
 * identical. LocalePort's docblock is explicit that it answers the locale of
 * the request the core is serving, and a queue drained by a scheduled task
 * has no request, so notification_templates would select rows by a value
 * nothing could supply. Quiet hours has the same shape: Principal carries
 * only a user id and a guest flag, and a UTC default would breach the very
 * statute no rule may defeat.
 *
 * It is optional and defaulted for the reason Ports.php already records: a
 * required new member turns a core upgrade into a fatal on every adapter that
 * has not been rebuilt. Until an adapter supplies one, quiet hours run in
 * site time and the documentation says so.
 */
interface RecipientProfilePort
{
    /** An IANA zone, or null when the host does not know. */
    public function timezoneFor(int $userId): ?string;

    /** A BCP 47 tag, or null when the host does not know. */
    public function localeFor(int $userId): ?string;
}
