<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Users;

/**
 * One timezone and one locale for everybody, for a host that cannot answer
 * per person.
 *
 * It is what Ports defaults to, so an adapter built before the port existed
 * keeps booting and answers exactly what the core assumed before: the site's
 * own settings. It answers null rather than a guess when it was given
 * nothing, because a statutory quiet-hours channel must refuse to send rather
 * than guess a zone.
 */
final class FixedRecipientProfile implements RecipientProfilePort
{
    public function __construct(
        private readonly ?string $timezone = null,
        private readonly ?string $locale = null,
    ) {
    }

    public function timezoneFor(int $userId): ?string
    {
        return $this->timezone;
    }

    public function localeFor(int $userId): ?string
    {
        return $this->locale;
    }
}
