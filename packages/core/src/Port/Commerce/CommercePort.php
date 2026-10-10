<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Commerce;

/**
 * The bridge to the CMS shop that sells courses in the free build:
 * EasyCommerce (or J2Commerce, HikaShop by plugin) on Joomla, WooCommerce on
 * WordPress. Whatever sells the course, the result is an entitlement written
 * back into the core by the shop-side plugin; this port only answers catalogue
 * questions the storefront needs.
 */
interface CommercePort
{
    public function priceForCourse(int $courseId): ?CoursePrice;

    public function purchaseUrlForCourse(int $courseId): ?string;
}
