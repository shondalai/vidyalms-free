<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceInterface;
use Shondalai\VidyaLMS\Core\Registry\Commerce\GrantsEntitlements;

/** A provider claiming words the domain confers elsewhere. */
final class OverreachingCommerceSource implements CommerceSourceInterface, GrantsEntitlements
{
    public function key(): string
    {
        return 'x_acme_everything';
    }

    public function label(): string
    {
        return 'Acme everything';
    }

    public function grantableEntitlementSources(): array
    {
        return [Entitlement::SOURCE_OPEN, Entitlement::SOURCE_ORG, 'x_acme_invented'];
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
