<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Http\ApiProblem;

/**
 * Type checks on a decoded request body, in one place so that every route
 * refuses the same shapes with the same message.
 *
 * These are shape checks only. Whether a title is too long, a state is one of
 * three words or a quiz belongs to a course is a rule, and rules live in use
 * cases; a handler that answered those would be a second rulebook nobody
 * updates.
 */
final class Body
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function requiredString(array $body, string $key): string
    {
        $value = $body[$key] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$key => 'A non-empty string is required.']);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function nullableString(array $body, string $key, ?string $default = null): ?string
    {
        if (!\array_key_exists($key, $body)) {
            return $default;
        }

        $value = $body[$key];

        if ($value !== null && !\is_string($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$key => 'A string or null is required.']);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function requiredInt(array $body, string $key): int
    {
        $value = $body[$key] ?? null;

        if (!\is_int($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$key => 'An integer is required.']);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function nullableInt(array $body, string $key, ?int $default = null): ?int
    {
        if (!\array_key_exists($key, $body)) {
            return $default;
        }

        $value = $body[$key];

        if ($value !== null && !\is_int($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$key => 'An integer or null is required.']);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function bool(array $body, string $key, bool $default): bool
    {
        if (!\array_key_exists($key, $body)) {
            return $default;
        }

        $value = $body[$key];

        if (!\is_bool($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$key => 'A boolean is required.']);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function number(array $body, string $key, ?float $default = null): float
    {
        $value = $body[$key] ?? null;

        if ($value === null && $default !== null) {
            return $default;
        }

        if (!\is_int($value) && !\is_float($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$key => 'A number is required.']);
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public static function object(array $body, string $key, bool $required = false): array
    {
        if (!\array_key_exists($key, $body)) {
            if ($required) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ApiProblem::validation([$key => 'An object is required.']);
            }

            return [];
        }

        $value = $body[$key];

        if (!\is_array($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$key => 'An object is required.']);
        }

        $object = [];

        /** @var mixed $item */
        foreach ($value as $name => $item) {
            $object[(string) $name] = $item;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<int>
     */
    public static function intList(array $body, string $key): array
    {
        $value = $body[$key] ?? null;

        if (!\is_array($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$key => 'An array of integers is required.']);
        }

        $ids = [];

        /** @var mixed $item */
        foreach ($value as $item) {
            if (!\is_int($item) || $item < 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ApiProblem::validation([$key => 'Every entry must be a positive integer.']);
            }

            $ids[] = $item;
        }

        return $ids;
    }

    /**
     * @param array<string, mixed> $body
     * @param list<string>         $default
     *
     * @return list<string>
     */
    public static function stringList(array $body, string $key, array $default = []): array
    {
        if (!\array_key_exists($key, $body)) {
            return $default;
        }

        $value = $body[$key];

        if (!\is_array($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$key => 'An array of strings is required.']);
        }

        $items = [];

        /** @var mixed $item */
        foreach ($value as $item) {
            if (!\is_string($item)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ApiProblem::validation([$key => 'Every entry must be a string.']);
            }

            $items[] = $item;
        }

        return $items;
    }
}
