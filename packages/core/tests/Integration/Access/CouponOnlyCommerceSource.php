<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceInterface;
use Shondalai\VidyaLMS\Core\Registry\Commerce\GrantsEntitlements;

/** A checkout that only ever redeems codes. */
final class CouponOnlyCommerceSource implements CommerceSourceInterface, GrantsEntitlements
{
    public function key(): string
    {
        return 'x_acme_coupons';
    }

    public function label(): string
    {
        return 'Acme coupons';
    }

    public function grantableEntitlementSources(): array
    {
        return [Entitlement::SOURCE_COUPON];
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
