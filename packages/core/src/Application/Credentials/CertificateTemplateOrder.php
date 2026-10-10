<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

/**
 * The one rule both reorder routes enforce: the list is exactly the ids that
 * are there, and the refusal names what was missing and what was extra.
 *
 * A partial reorder would silently drop a page from the render or an element
 * from the draw, and a refusal that says only "invalid" leaves the author
 * guessing which of forty ids they mistyped.
 */
final class CertificateTemplateOrder
{
    private function __construct()
    {
    }

    /**
     * @param list<int> $given
     * @param list<int> $known
     */
    public static function check(CertificateTemplateFields $fields, string $key, array $given, array $known): void
    {
        if (!$fields->isValid()) {
            return;
        }

        $missing = array_values(array_diff($known, $given));
        $extra = array_values(array_diff($given, $known));
        $duplicated = \count($given) !== \count(array_unique($given));

        if ($missing === [] && $extra === [] && !$duplicated) {
            return;
        }

        $problems = [];

        if ($missing !== []) {
            $problems[] = 'missing ' . implode(', ', $missing);
        }

        if ($extra !== []) {
            $problems[] = 'unknown ' . implode(', ', $extra);
        }

        if ($duplicated) {
            $problems[] = 'repeated ids';
        }

        $fields->reject($key, 'The list must be exactly these ids: ' . implode('; ', $problems) . '.');
    }
}
