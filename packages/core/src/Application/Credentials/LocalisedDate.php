<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

/**
 * A date a person reads, rather than the one a database stores.
 *
 * The render path formatted the completion date as Y-m-d, which is a machine
 * format printed on a human artefact. Formatting goes through the locale the
 * render already carries.
 *
 * ext-intl is used when the host has it and is not required: it is not in
 * this package's composer requirements, plenty of shared hosts ship without
 * it, and a certificate that cannot render because a PHP extension is missing
 * is a worse answer than one that renders a long date in a documented
 * fallback pattern. The fallback covers the day-month order the common
 * locales actually use rather than pretending one order fits everybody.
 */
final class LocalisedDate
{
    /**
     * Locales whose conventional order is month first. Everything else is
     * day first, which is the majority and the safer default: a reader who
     * sees "3 July 2026" cannot misread it, while "07/03/2026" is two
     * different days depending on where the reader lives.
     *
     * @var list<string>
     */
    private const MONTH_FIRST = ['en-US', 'en-PH', 'ja-JP', 'zh-CN', 'ko-KR'];

    private function __construct()
    {
    }

    public static function long(\DateTimeImmutable $moment, string $locale, string $timezone = 'UTC'): string
    {
        $local = $moment->setTimezone(self::zone($timezone));

        if (class_exists(\IntlDateFormatter::class)) {
            $formatter = new \IntlDateFormatter(
                str_replace('-', '_', $locale),
                \IntlDateFormatter::LONG,
                \IntlDateFormatter::NONE,
                $timezone,
            );

            $formatted = $formatter->format($local);

            if (\is_string($formatted) && $formatted !== '') {
                return $formatted;
            }
        }

        return \in_array($locale, self::MONTH_FIRST, true)
            ? $local->format('F j, Y')
            : $local->format('j F Y');
    }

    /**
     * The machine form, which the credential body and every API field use.
     * A credential is read by software and a certificate is read by a person,
     * and the two want different things from the same instant.
     */
    public static function iso(\DateTimeImmutable $moment): string
    {
        return $moment->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    private static function zone(string $timezone): \DateTimeZone
    {
        try {
            return new \DateTimeZone($timezone);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }
}
