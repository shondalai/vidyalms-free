<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What a preset accessor may read beyond its own record.
 *
 * A preset is data, but its label is copy, and a preset that has to resolve
 * its label to a string at boot can never be localised per request: the
 * picker would answer whichever language the site happened to be in when the
 * kernel was built. The context carries the request locale so the accessor
 * runs at read time.
 *
 * It is on every accessor rather than only on label(), for the reason the
 * whole design gives for contexts: a context parameter is the only signature
 * shape that can grow later without breaking an implementation somebody else
 * ships, and adding it to some methods but not others is the more expensive
 * mistake of the two, because it has to be done twice.
 */
final class PresetContext
{
    private function __construct(
        public readonly string $locale,
    ) {
    }

    public static function create(string $locale = 'en-GB'): self
    {
        return new self($locale);
    }

    /**
     * The context a caller outside a request uses: the catalogue generator,
     * an importer resolving a source alias, a boot-time self check.
     */
    public static function neutral(): self
    {
        return new self('en-GB');
    }
}
