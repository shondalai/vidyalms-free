<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use Shondalai\VidyaLMS\Core\Provider\Commerce\CmsShopCommerceSource;
use Shondalai\VidyaLMS\Core\Tests\Support\StubCommerce;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class CmsShopCommerceSourceTest extends TestCase
{
    private StubCommerce $commerce;

    private CmsShopCommerceSource $source;

    protected function setUp(): void
    {
        $this->commerce = new StubCommerce();
        $this->source = new CmsShopCommerceSource($this->commerce, new StubTranslator());
    }

    public function testKeyAndLabel(): void
    {
        self::assertSame('cms-shop', $this->source->key());
        self::assertSame('vidyalms.commerce_source.cms_shop', $this->source->label());
    }

    public function testDelegatesPriceAndPurchaseUrlToTheCommercePort(): void
    {
        $price = new CoursePrice(4999, 'USD', '$49.99');
        $this->commerce->listCourse(5, $price, 'https://shop.example.test/course-5');

        self::assertSame($price, $this->source->priceFor(['id' => 5]));
        self::assertSame('https://shop.example.test/course-5', $this->source->purchaseUrlFor(['id' => 5]));
    }

    public function testHandlesNumericStringIdsFromRawRows(): void
    {
        $price = new CoursePrice(1000, 'EUR');
        $this->commerce->listCourse(7, $price, 'https://shop.example.test/course-7');

        self::assertSame($price, $this->source->priceFor(['id' => '7']));
    }

    public function testUnlistedCourseHasNoPriceAndNoUrl(): void
    {
        self::assertNull($this->source->priceFor(['id' => 99]));
        self::assertNull($this->source->purchaseUrlFor(['id' => 99]));
    }

    public function testRowWithoutAUsableIdYieldsNull(): void
    {
        self::assertNull($this->source->priceFor([]));
        self::assertNull($this->source->priceFor(['id' => 'abc']));
        self::assertNull($this->source->purchaseUrlFor(['id' => 0]));
    }
}
