<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Port\Commerce\CommercePort;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;

final class StubCommerce implements CommercePort
{
    /** @var array<int, CoursePrice> */
    private array $prices = [];

    /** @var array<int, string> */
    private array $urls = [];

    public function listCourse(int $courseId, CoursePrice $price, string $purchaseUrl): void
    {
        $this->prices[$courseId] = $price;
        $this->urls[$courseId] = $purchaseUrl;
    }

    public function priceForCourse(int $courseId): ?CoursePrice
    {
        return $this->prices[$courseId] ?? null;
    }

    public function purchaseUrlForCourse(int $courseId): ?string
    {
        return $this->urls[$courseId] ?? null;
    }
}
