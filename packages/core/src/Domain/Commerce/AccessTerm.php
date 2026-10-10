<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Commerce;

/**
 * How long access lasts, independently of how the offer is billed.
 *
 * It is carried on a price option so the drawn "12 months' access" subline
 * is data rather than a hardcoded string, which is the third of the three
 * catalogue contract additions.
 */
final class AccessTerm
{
    public const MODE_LIFETIME = 'lifetime';
    public const MODE_FIXED_LENGTH = 'fixed_length';
    public const MODE_FIXED_DATE = 'fixed_date';

    public const MODES = [self::MODE_LIFETIME, self::MODE_FIXED_LENGTH, self::MODE_FIXED_DATE];

    public const UNITS = ['day', 'week', 'month', 'year'];

    public function __construct(
        public readonly string $mode,
        public readonly ?int $length = null,
        public readonly ?string $unit = null,
        public readonly ?\DateTimeImmutable $endsAt = null,
    ) {
        if (!\in_array($mode, self::MODES, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unknown access mode "%s".', $mode));
        }

        if ($mode === self::MODE_FIXED_LENGTH && ($length === null || $length < 1 || $unit === null)) {
            throw new \InvalidArgumentException('A fixed-length access term needs a positive length and a unit.');
        }

        if ($unit !== null && !\in_array($unit, self::UNITS, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unknown access unit "%s".', $unit));
        }

        if ($mode === self::MODE_FIXED_DATE && $endsAt === null) {
            throw new \InvalidArgumentException('A fixed-date access term needs the date it ends on.');
        }
    }

    public static function lifetime(): self
    {
        return new self(self::MODE_LIFETIME);
    }
}
