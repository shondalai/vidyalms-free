<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Commerce;

/**
 * A display price supplied by whatever sells the course. Amount is in minor
 * units (cents, paise) to keep arithmetic exact.
 */
final class CoursePrice
{
    public function __construct(
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly ?string $formatted = null,
    ) {
    }
}
