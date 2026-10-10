<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Reporting;

/**
 * An opaque position in a stable sort order.
 *
 * Offset paging makes the engine build and discard every skipped row, and,
 * worse, lets rows shift between pages under concurrent inserts and deletes,
 * producing duplicates and gaps. activity_log is written from fifteen-second
 * heartbeats, so an offset-paged export of it **will** duplicate and skip rows
 * while it runs. The sort key must be stable, indexed and unique, which is why
 * the timeline pages on (occurred_at, id) rather than on occurred_at alone.
 *
 * The cursor is opaque to the caller on purpose. A client that could compose
 * one could page a report by a key it chose, which is the group-leader leak
 * wearing a different hat: the scope belongs to the run, not to the page
 * token. It carries no personal data, only the ordering parts.
 */
final class KeysetCursor
{
    /** @param list<int|string> $parts the sort key values of the last row served */
    private function __construct(
        public readonly array $parts,
    ) {
    }

    /** @param list<int|string> $parts */
    public static function of(array $parts): self
    {
        if ($parts === []) {
            throw new \InvalidArgumentException('A cursor names at least one sort key part.');
        }

        return new self($parts);
    }

    /**
     * Reads a cursor a previous page produced, or null.
     *
     * Null for anything unreadable rather than an exception: a stale or
     * truncated token from an old client should start the export again from
     * the beginning, not fail it.
     */
    public static function decode(?string $encoded): ?self
    {
        if ($encoded === null || $encoded === '') {
            return null;
        }

        $json = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($json === false) {
            return null;
        }

        try {
            $decoded = json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || $decoded === []) {
            return null;
        }

        $parts = [];

        foreach ($decoded as $part) {
            if (!\is_int($part) && !\is_string($part)) {
                return null;
            }

            $parts[] = $part;
        }

        return new self($parts);
    }

    /** URL-safe base64, so a cursor survives a query string without escaping. */
    public function encode(): string
    {
        return rtrim(strtr(base64_encode(json_encode($this->parts, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    public function part(int $index): int|string|null
    {
        return $this->parts[$index] ?? null;
    }
}
