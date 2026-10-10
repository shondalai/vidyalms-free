<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;

/**
 * Translates against the real neutral catalogue, the way a CMS adapter does:
 * known keys resolve to their catalogue string, unknown keys fall back to the
 * key itself, and only the given parameters are substituted, so placeholders
 * without a parameter stay literal. Use it where a test needs the seeded copy
 * rather than key echoes.
 */
final class CatalogueTranslator implements TranslatorPort
{
    /** @var array<string, string> */
    private readonly array $catalogue;

    public function __construct()
    {
        /** @var array<string, string> $catalogue */
        $catalogue = require \dirname(__DIR__, 2) . '/i18n/catalogue.php';

        $this->catalogue = $catalogue;
    }

    public function translate(string $key, array $parameters = []): string
    {
        $result = $this->catalogue[$key] ?? $key;

        foreach ($parameters as $name => $value) {
            $result = str_replace('{' . $name . '}', (string) $value, $result);
        }

        return $result;
    }
}
