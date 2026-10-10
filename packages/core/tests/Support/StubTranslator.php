<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;

/**
 * Returns the key with {placeholders} substituted, so assertions can target
 * keys instead of copy.
 *
 * A test that needs to tell a translated string from an untranslated key
 * defines the key first with {@see define()}. Nothing is defined by default,
 * so every assertion written against a key keeps reading that key.
 */
final class StubTranslator implements TranslatorPort
{
    /** @var array<string, string> */
    private array $defined = [];

    /** Gives one key a text a key cannot be mistaken for. */
    public function define(string $key, string $text): void
    {
        $this->defined[$key] = $text;
    }

    public function translate(string $key, array $parameters = []): string
    {
        $result = $this->defined[$key] ?? $key;

        foreach ($parameters as $name => $value) {
            $result = str_replace('{' . $name . '}', (string) $value, $result);
        }

        return $result;
    }
}
