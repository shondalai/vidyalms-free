<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

/**
 * Reading one value out of a foreign row.
 *
 * Every row these importers touch is foreign data, and the failure that used
 * to end a whole migration was a source value reaching a typed parameter: an
 * Error is not caught by a handler that names DomainException. So nothing
 * here throws and nothing here returns a type the caller did not ask for. A
 * value that is not what it should be reads as absent, which is what it means.
 *
 * firstOf() is the other half of the unconfirmed-schema rule. Where a table's
 * shape could not be confirmed from a published source, the read selects
 * every column and the mapper asks for the key it expects with the
 * alternatives it has seen documented elsewhere, in the order it prefers
 * them. That is a guess written down in one place a maintainer can correct
 * against a real install, rather than a guess spread through a SELECT list
 * where one wrong word costs the whole table.
 */
final class SourceValue
{
    private function __construct()
    {
    }

    public static function text(mixed $value): string
    {
        return \is_string($value) || \is_int($value) || \is_float($value) ? trim((string) $value) : '';
    }

    /**
     * The text, or null when there is none, for the many core parameters that
     * take a nullable string and mean "not set" by null.
     */
    public static function nullable(mixed $value): ?string
    {
        $text = self::text($value);

        return $text === '' ? null : $text;
    }

    public static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * A foreign truth value. Joomla components write these as 1 and 0, as
     * "1" and "0", and as "yes" and "no", so all three read the same way.
     */
    public static function boolean(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        $text = strtolower(self::text($value));

        return \in_array($text, ['1', 'true', 'yes', 'y', 'on'], true);
    }

    /**
     * The first of these keys the row actually carries a value under.
     *
     * @param array<string, mixed> $row
     * @param list<string>         $keys in the order this mapper prefers them
     */
    public static function firstOf(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (\array_key_exists($key, $row) && self::text($row[$key]) !== '') {
                return $row[$key];
            }
        }

        return null;
    }

    /**
     * Decodes one of a product's JSON or Joomla registry columns. A column
     * that is null, empty or not an object reads as no configuration at all.
     *
     * @return array<string, mixed>
     */
    public static function decode(mixed $value): array
    {
        if (\is_array($value)) {
            /** @var array<string, mixed> $value */
            return $value;
        }

        if (!\is_string($value) || trim($value) === '') {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($value, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * A duration held in whole minutes, in seconds, or null when it is not a
     * number or is too large for the column to hold.
     *
     * The digits are counted before they are multiplied, because the
     * multiplication is what overflows: a twenty digit source value times
     * sixty is a float, which a function returning ?int may not return, and
     * that TypeError is not caught by any DomainException handler.
     */
    public static function minutesAsSeconds(mixed $minutes, int $limit): ?int
    {
        $text = self::text($minutes);

        if (preg_match('/^\d+$/', $text) !== 1) {
            return null;
        }

        $text = ltrim($text, '0');

        if ($text === '' || \strlen($text) > 10) {
            return null;
        }

        $total = (int) $text * 60;

        return $total < 1 || $total > $limit ? null : $total;
    }

    /**
     * Seconds straight out of a source column, bounded the same way.
     */
    public static function seconds(mixed $seconds, int $limit): ?int
    {
        $total = self::integer($seconds);

        return $total < 1 || $total > $limit ? null : $total;
    }

    /**
     * A datetime the core will accept, or nothing. Zero dates and anything
     * else unparseable read as "no date", which is what they mean.
     */
    public static function datetime(mixed $value): ?string
    {
        $text = self::text($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $text) !== 1) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $text, new \DateTimeZone('UTC'));

        return $parsed !== false && $parsed->format('Y-m-d H:i:s') === $text ? $text : null;
    }

    /**
     * Whether a source value is a completion rather than a start: a real
     * datetime, or a truthy flag. Products spell "finished" both ways and an
     * importer that only understood one of them would silently import half a
     * cohort's history.
     */
    public static function completed(mixed $value): bool
    {
        if (self::datetime($value) !== null) {
            return true;
        }

        $text = self::text($value);

        if ($text === '' || str_starts_with($text, '0000-00-00')) {
            return false;
        }

        return self::boolean($value) || preg_match('/^\d{4}-\d{2}-\d{2}/', $text) === 1;
    }
}
