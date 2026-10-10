<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Commerce;

/**
 * How an offer is billed, independently of how long access lasts.
 *
 * The separation from AccessTerm is the whole design: all four combinations
 * occur (billed once and granted forever, billed once for a fixed term,
 * billed recurrently while paid, billed a fixed number of times and granted
 * forever), and neither term derives from the other.
 */
final class BillingTerm
{
    public const MODE_ONE_TIME = 'one_time';
    public const MODE_RECURRING = 'recurring';
    public const MODE_INSTALMENT = 'instalment';
    public const MODE_FREE = 'free';

    public const MODES = [self::MODE_ONE_TIME, self::MODE_RECURRING, self::MODE_INSTALMENT, self::MODE_FREE];

    public const INTERVALS = ['day', 'week', 'month', 'year'];

    public function __construct(
        public readonly string $mode,
        public readonly ?string $interval = null,
        public readonly int $intervalCount = 1,
        /** 0 means indefinite, which is the recurring case rather than a missing value. */
        public readonly int $totalCycles = 0,
    ) {
        if (!\in_array($mode, self::MODES, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unknown billing mode "%s".', $mode));
        }

        if ($interval !== null && !\in_array($interval, self::INTERVALS, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unknown billing interval "%s".', $interval));
        }

        if ($intervalCount < 1) {
            throw new \InvalidArgumentException('A billing interval count is at least one.');
        }

        if ($totalCycles < 0) {
            throw new \InvalidArgumentException('A cycle count is zero (indefinite) or positive.');
        }
    }

    public static function oneTime(): self
    {
        return new self(self::MODE_ONE_TIME);
    }

    public function isRecurring(): bool
    {
        return $this->mode === self::MODE_RECURRING || $this->mode === self::MODE_INSTALMENT;
    }
}
