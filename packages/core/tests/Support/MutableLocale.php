<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;

/**
 * A request locale a test can move after the environment is built.
 *
 * A context object populated with a hardcoded default is indistinguishable
 * from no context at all, so the assertion that catches it is what a provider
 * actually receives on a non-default locale. Rebuilding every use case around
 * a second environment to change one string would make that assertion cost
 * more than it is worth.
 */
final class MutableLocale implements LocalePort
{
    public function __construct(private string $locale = 'en-GB')
    {
    }

    public function current(): string
    {
        return $this->locale;
    }

    public function use(string $locale): void
    {
        $this->locale = $locale;
    }
}
