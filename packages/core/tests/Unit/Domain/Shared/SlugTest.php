<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Domain\Shared;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\Slug;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

final class SlugTest extends TestCase
{
    public function testAcceptsWellFormedSlugs(): void
    {
        self::assertSame('intro-to-joomla', Slug::fromString('intro-to-joomla')->value);
        self::assertSame('a1', Slug::fromString('A1')->value);
    }

    public function testRejectsMalformedSlugs(): void
    {
        foreach (['', '-leading', 'trailing-', 'double--hyphen', 'with space', 'uber?'] as $bad) {
            try {
                Slug::fromString($bad);
                self::fail(sprintf('"%s" should have been rejected.', $bad));
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('slug', $exception->errors);
            }
        }
    }

    public function testDerivesFromTitle(): void
    {
        self::assertSame('intro-to-joomla-6', Slug::fromTitle('Intro to Joomla 6!')->value);
        self::assertSame('cafe-basics', Slug::fromTitle('Café  Basics')->value);
    }

    public function testRejectsUnderivableTitles(): void
    {
        $this->expectException(ValidationException::class);
        Slug::fromTitle('!!!');
    }
}
