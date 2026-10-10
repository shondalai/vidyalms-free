<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\I18n;

/**
 * The locale of the request the core is serving.
 *
 * It is its own port rather than a method on TranslatorPort, and the reason is
 * a failure that was observed rather than imagined. TranslatorPort is not only
 * adapter surface: a paid package implements it too, because its own strings
 * live in its own catalogue and the free build's translator cannot answer
 * them. Adding a method to it therefore turns a free-build upgrade into a
 * fatal on every site whose paid package has not been upgraded in the same
 * minute, at class load, before any version guard can run. A white screen on a
 * site that did nothing wrong is a worse defect than the one being fixed.
 *
 * A separate port is additive in both directions: nothing outside the adapters
 * implements it, and an adapter that predates it keeps booting because Ports
 * defaults it.
 *
 * Three context objects carry a locale a provider is meant to read (decoding
 * is locale sensitive, text comparison is locale sensitive, and an authoring
 * mask has to show the site's decimal separator), and before this port existed
 * every one of them was populated with the same hardcoded default because
 * nothing in the ports could say what the locale was. A de-DE learner typing
 * "5,5" was then parsed under en-GB rules at save-time validation.
 */
interface LocalePort
{
    /**
     * The BCP 47 tag of the current request, such as "en-GB" or "de-DE".
     */
    public function current(): string;
}
