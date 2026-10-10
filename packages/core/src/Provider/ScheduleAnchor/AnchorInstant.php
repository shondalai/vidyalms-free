<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\ScheduleAnchor;

/**
 * Reading an instant out of a row or a payload, in one place.
 *
 * Every anchor faces the same two shapes: an RFC 3339 string authored through
 * the API, and the port boundary's "Y-m-d H:i:s" in UTC. Parsing them in five
 * providers is four chances to disagree about which one is UTC.
 */
final class AnchorInstant
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(array $payload, string $key): ?\DateTimeImmutable
    {
        return self::parse($payload[$key] ?? null);
    }

    /**
     * @param array<string, mixed>|null $row
     */
    public static function fromRow(?array $row, string $column): ?\DateTimeImmutable
    {
        return $row === null ? null : self::parse($row[$column] ?? null);
    }

    public static function parse(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
