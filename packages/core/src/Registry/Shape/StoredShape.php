<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Shape;

/**
 * The envelope around a JSON payload whose shape belongs to a provider.
 *
 * Two columns hold provider-shaped JSON, lesson_content.content and
 * questions.definition, and neither carried any way to tell which version of
 * a provider wrote it. That is fine while a key has exactly one implementation
 * for ever. It stops being fine the moment a package replaces a registered
 * provider (the documented override path), because every stored row is then
 * reinterpreted by the new one with nothing to key a migration against.
 *
 * So a stored payload carries its version under a reserved key and the body
 * beside it. The envelope never leaves the repository: it is added on write
 * and stripped on read, so the API contract and every provider still see the
 * body alone.
 *
 * A payload written before this existed has no version key and reads as
 * version 0, which is what an upgrader is handed for a legacy row.
 */
final class StoredShape
{
    /**
     * Underscored so it cannot collide with a provider's own field. A
     * provider that ships a top-level "_v" of its own would be shadowed, so
     * no provider may.
     */
    public const VERSION_KEY = '_v';

    /** What a payload with no version key is treated as. */
    public const UNVERSIONED = 0;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $stored
     */
    public static function versionOf(array $stored): int
    {
        $version = $stored[self::VERSION_KEY] ?? null;

        return \is_int($version) && $version > 0 ? $version : self::UNVERSIONED;
    }

    /**
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public static function bodyOf(array $stored): array
    {
        unset($stored[self::VERSION_KEY]);

        return $stored;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    public static function wrap(array $body, int $version): array
    {
        unset($body[self::VERSION_KEY]);

        return [self::VERSION_KEY => $version] + $body;
    }
}
