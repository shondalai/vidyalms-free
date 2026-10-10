<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\I18n;

/**
 * String translation. Keys come from the neutral catalogue that is compiled
 * to Joomla INI files and WordPress gettext at build time. Parameters are
 * replaced as {name} placeholders.
 *
 * The request locale is LocalePort, deliberately not a method here: this
 * interface is implemented by paid packages as well as by adapters, because
 * their own strings live in their own catalogues, so adding a method to it
 * turns a free-build upgrade into a fatal at class load on every site whose
 * paid package has not been upgraded in the same minute.
 */
interface TranslatorPort
{
    /**
     * @param array<string, scalar|null> $parameters
     */
    public function translate(string $key, array $parameters = []): string;
}
