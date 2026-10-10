<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\I18n;

/**
 * One locale, for a caller that has no host to ask.
 *
 * It is what Ports defaults to, so an adapter built before LocalePort existed
 * keeps booting and answers exactly what it answered before: en-GB everywhere.
 * It is also what a CLI script, a fixture and the catalogue generator use,
 * because none of them is serving a request.
 */
final class FixedLocale implements LocalePort
{
    public const DEFAULT = 'en-GB';

    public function __construct(private readonly string $locale = self::DEFAULT)
    {
    }

    public function current(): string
    {
        return $this->locale === '' ? self::DEFAULT : $this->locale;
    }
}
