<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceInterface;

/** A storefront, which is not a grantor. */
final class PriceOnlyCommerceSource implements CommerceSourceInterface
{
    public function key(): string
    {
        return 'x_acme_prices';
    }

    public function label(): string
    {
        return 'Acme prices';
    }

    public function priceFor(array $course): ?CoursePrice
    {
        return null;
    }

    public function purchaseUrlFor(array $course): ?string
    {
        return null;
    }
}
