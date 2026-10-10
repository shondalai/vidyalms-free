<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Commerce;

use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Port\Commerce\CommercePort;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Commerce\CommerceSourceInterface;
use Shondalai\VidyaLMS\Core\Registry\Commerce\GrantsEntitlements;

/**
 * Uses the CMS shop to resolve prices and product links for paid courses.
 * Confirmed shop updates can grant purchase or subscription access through
 * the core; VidyaLMS never collects or refunds payments.
 */
final class CmsShopCommerceSource implements CommerceSourceInterface, GrantsEntitlements
{
    public function grantableEntitlementSources(): array
    {
        return [
            Entitlement::SOURCE_PURCHASE,
            Entitlement::SOURCE_SUBSCRIPTION,
            Entitlement::SOURCE_COUPON,
            Entitlement::SOURCE_SEAT,
        ];
    }

    public function __construct(
        private readonly CommercePort $commerce,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return 'cms-shop';
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.commerce_source.cms_shop');
    }

    public function priceFor(array $course): ?CoursePrice
    {
        $courseId = $this->courseId($course);

        return $courseId === null ? null : $this->commerce->priceForCourse($courseId);
    }

    public function purchaseUrlFor(array $course): ?string
    {
        $courseId = $this->courseId($course);

        return $courseId === null ? null : $this->commerce->purchaseUrlForCourse($courseId);
    }

    /**
     * @param array<string, mixed> $course
     */
    private function courseId(array $course): ?int
    {
        if (!isset($course['id']) || !is_numeric($course['id'])) {
            return null;
        }

        $courseId = (int) $course['id'];

        return $courseId > 0 ? $courseId : null;
    }
}
