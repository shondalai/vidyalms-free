<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Credentials;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateLayout;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;

/**
 * The layout service exists so no element implementation ever manages its own
 * position, which is mod_customcert's own 5.2.0 correction: with the
 * arithmetic in nineteen element classes, a fix to the centre reference point
 * was nineteen fixes and two of them were always missed.
 */
final class CertificateLayoutTest extends TestCase
{
    public function testTheThreeReferencePointsPlaceTheSameElementDifferently(): void
    {
        $layout = new CertificateLayout();
        $page = new CertificateTemplatePage(1, 1);

        $left = $layout->boxFor($page, $this->element(100, 40, CertificateTemplateElement::REFPOINT_TOP_LEFT));
        $centre = $layout->boxFor($page, $this->element(100, 40, CertificateTemplateElement::REFPOINT_TOP_CENTER));
        $right = $layout->boxFor($page, $this->element(100, 40, CertificateTemplateElement::REFPOINT_TOP_RIGHT));

        self::assertSame(100.0, $left->left);
        self::assertSame(80.0, $centre->left);
        self::assertSame(60.0, $right->left);
        self::assertSame(20.0, $left->top);
    }

    public function testAnElementWithNoWidthRunsToThePageMargin(): void
    {
        $layout = new CertificateLayout();
        $page = new CertificateTemplatePage(1, 1);

        $box = $layout->boxFor($page, $this->element(40, null, CertificateTemplateElement::REFPOINT_TOP_LEFT));

        // 297mm wide, 10mm right margin, placed at 40mm.
        self::assertSame(247.0, $box->width);
    }

    public function testAnElementPlacedOffThePaperIsNotSilentlyMoved(): void
    {
        $layout = new CertificateLayout();
        $page = new CertificateTemplatePage(1, 1);

        $box = $layout->boxFor($page, $this->element(10, 400, CertificateTemplateElement::REFPOINT_TOP_RIGHT));

        // An authoring mistake the designer shows. Clamping it would hide the
        // mistake while changing what a frozen snapshot renders.
        self::assertSame(-390.0, $box->left);
    }

    public function testTheStyleIsPhysicalUnitsAndNotPixels(): void
    {
        $layout = new CertificateLayout();
        $page = new CertificateTemplatePage(1, 1);
        $box = $layout->boxFor($page, $this->element(40, 120, CertificateTemplateElement::REFPOINT_TOP_LEFT));

        $style = $box->style();

        // A page declared 297mm wide and an element placed at 40mm mean the
        // same thing in a print preview and in a PDF engine, which pixels
        // would not.
        self::assertStringContainsString('left: 40mm', $style);
        self::assertStringContainsString('width: 120mm', $style);
        self::assertStringContainsString('position: absolute', $style);
        self::assertStringNotContainsString('px', $style);
    }

    public function testThePageFrameCarriesItsBackground(): void
    {
        $layout = new CertificateLayout();
        $page = new CertificateTemplatePage(1, 1, 1, 210, 297, 'portrait', 10, 10, 10, 10, 'media/paper.png');

        $style = $layout->pageStyle($page);

        self::assertStringContainsString('width: 210mm', $style);
        self::assertStringContainsString('height: 297mm', $style);
        self::assertStringContainsString('background-image: url(media/paper.png)', $style);
    }

    private function element(int $posX, ?int $width, string $refPoint): CertificateTemplateElement
    {
        return new CertificateTemplateElement(1, 1, 'text', [], 1, 1, null, $posX, 20, $width, null, $refPoint);
    }
}
