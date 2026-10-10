<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What an item mapper may read while translating a foreign item into a
 * canonical one, or back.
 *
 * The free build registers no mappers. The interface, the registry and this
 * context ship anyway, for the reason the design gives for the other
 * registries: a seam only Pro uses is a seam nobody tests, and adding the
 * context parameter after a third party has written a mapper breaks it.
 */
final class MapperContext
{
    public const DIRECTION_IMPORT = 'import';
    public const DIRECTION_EXPORT = 'export';

    /**
     * @param array<string, mixed> $options
     */
    private function __construct(
        public readonly string $format,
        public readonly string $direction,
        public readonly string $locale,
        public readonly array $options,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function create(
        string $format,
        string $direction = self::DIRECTION_IMPORT,
        string $locale = 'en-GB',
        array $options = [],
    ): self {
        return new self($format, $direction, $locale, $options);
    }
}
