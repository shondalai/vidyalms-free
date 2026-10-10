<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

/** Supports both path-based routes and host query routes with a value slot. */
final class PublicLink
{
    public static function append(string $base, string $value): string
    {
        $encoded = rawurlencode($value);
        return str_contains($base, '{value}')
            ? str_replace('{value}', $encoded, $base)
            : rtrim($base, '/') . '/' . $encoded;
    }
}
