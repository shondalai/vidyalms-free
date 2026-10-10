<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Commerce;

/**
 * Whether a displayed amount already carries tax.
 *
 * Three values rather than two, because the third is the honest answer on a
 * catalogue page that has not resolved a country yet. Directive 98/6/EC and
 * the UK DMCCA 2024 both require the consumer price to be the final one, and
 * US convention quotes exclusive, so a storefront that cannot yet say which
 * it is showing must say so rather than pick the reading that flatters it.
 */
enum TaxTreatment: string
{
    /** Tax is included in the amount shown. */
    case Gross = 'gross';

    /** Tax is added at checkout and is not in the amount shown. */
    case Net = 'net';

    /** No jurisdiction has been resolved, so the price is provisional. */
    case Undetermined = 'undetermined';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
