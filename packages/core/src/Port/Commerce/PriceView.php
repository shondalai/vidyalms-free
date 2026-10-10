<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Commerce;

/**
 * Everything a storefront needs to print a price for one course.
 *
 * A headline option plus the rest, which is the shape the catalogue card, the
 * landing sidebar, a plan picker and a coupon-adjusted price all render from
 * without a second contract. The same course can legitimately be sold
 * outright, rented for twelve months, included in a bundle and granted by a
 * membership at once, so a single amount was never going to be enough.
 *
 * `resolvedCountry` is on the view rather than on each option because the
 * jurisdiction is a property of the ask, not of the offer, and because a
 * price computed from a guessed country has to be marked provisional and the
 * public page has to be able to offer the buyer a way to correct it. A guess
 * the buyer cannot correct is worse than no guess.
 */
final class PriceView
{
    /** @param list<PriceOption> $options the headline first */
    public function __construct(
        public readonly PriceOption $headline,
        public readonly array $options = [],
        public readonly ?string $resolvedCountry = null,
        public readonly ?string $resolvedSubdivision = null,
        /** The commerce source that answered, so the storefront can say who priced this. */
        public readonly string $sourceKey = '',
    ) {
    }

    /** @return list<PriceOption> */
    public function allOptions(): array
    {
        return $this->options === [] ? [$this->headline] : $this->options;
    }

    /** True when nothing on this view may be presented as a final consumer price. */
    public function isProvisional(): bool
    {
        return $this->headline->provisional;
    }
}
