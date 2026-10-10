<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class OrganisationInput
{
    /** @param array<string, mixed> $input */
    public static function integer(array $input, string $key, int $maximum = 2147483646): int
    {
        $value = $input[$key] ?? null;
        if (!\is_int($value) || $value < 1 || $value > $maximum) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, 'Expected a positive integer within the supported range.');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    public static function text(array $input, string $key, int $maximum): string
    {
        $value = $input[$key] ?? null;
        if (
            !\is_string($value) || trim($value) === '' || mb_strlen($value) > $maximum
            || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
        ) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, 'Expected non-empty text within the supported length.');
        }

        return trim($value);
    }

    public static function page(int $page, int $perPage): void
    {
        if ($page < 1 || $page > 1000000 || $perPage < 1 || $perPage > 100) {
            throw ValidationException::forField('page', 'Use a positive page and between 1 and 100 rows per page.');
        }
    }

    public static function datetime(mixed $value, string $key): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!\is_string($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, 'Expected a UTC datetime.');
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        if ($date === false || $date->format('Y-m-d H:i:s') !== $value) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($key, 'Expected a UTC datetime in Y-m-d H:i:s format.');
        }

        return $value;
    }

    /** @param array<string, mixed> $input
     * @param list<string> $keys
     */
    public static function only(array $input, array $keys): void
    {
        foreach (array_keys($input) as $key) {
            if (!\in_array($key, $keys, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($key, 'This field is not supported by this operation.');
            }
        }
    }
}
