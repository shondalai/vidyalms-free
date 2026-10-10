<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Notification;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/** Canonical, bounded BCP 47 identities shared by authoring and delivery. */
final class NotificationTemplateLocale
{
    public const DEFAULT = 'en-GB';

    public static function canonicalize(string $locale): string
    {
        $locale = trim($locale);

        if (
            $locale === ''
            || \strlen($locale) > 20
            || preg_match('/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{1,8})*$/D', $locale) !== 1
        ) {
            throw ValidationException::forField('locale', 'Use a language tag such as en-GB or fr.');
        }

        $parts = explode('-', $locale);
        $parts[0] = strtolower($parts[0]);
        $extension = false;

        foreach ($parts as $index => $part) {
            if ($index === 0) {
                continue;
            }

            if (\strlen($part) === 1) {
                $parts[$index] = strtolower($part);
                $extension = true;

                continue;
            }

            if ($extension) {
                $parts[$index] = strtolower($part);

                continue;
            }

            if (\strlen($part) === 4 && ctype_alpha($part)) {
                $parts[$index] = ucfirst(strtolower($part));

                continue;
            }

            if (\strlen($part) === 2 && ctype_alpha($part)) {
                $parts[$index] = strtoupper($part);

                continue;
            }

            $parts[$index] = strtolower($part);
        }

        return implode('-', $parts);
    }

    /**
     * Recipient exact, recipient language, site exact, site language, then
     * the canonical English source. Callers supply recipient then site.
     *
     * @param list<string> $locales
     *
     * @return list<string>
     */
    public static function chain(array $locales): array
    {
        $chain = [];
        $seen = [];

        foreach ($locales as $locale) {
            if (!\is_string($locale)) {
                throw ValidationException::forField('locale', 'Locale chains may contain strings only.');
            }

            $exact = self::canonicalize($locale);
            self::append($chain, $seen, $exact);
            self::append($chain, $seen, explode('-', $exact, 2)[0]);
        }

        self::append($chain, $seen, self::DEFAULT);

        return $chain;
    }

    /** @param list<string> $chain
     * @param array<string, true> $seen
     */
    private static function append(array &$chain, array &$seen, string $locale): void
    {
        $key = strtolower($locale);

        if (isset($seen[$key])) {
            return;
        }

        $seen[$key] = true;
        $chain[] = $locale;
    }
}
