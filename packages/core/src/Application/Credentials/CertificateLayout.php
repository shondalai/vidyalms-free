<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Registry\Context\AssetPath;

/**
 * The one place that turns a stored position into a box on a page.
 *
 * It exists so no element implementation ever manages its own position,
 * alignment or reference point, which is mod_customcert's own 5.2.0
 * correction: with the arithmetic in nineteen element classes, a fix to the
 * centre reference point was nineteen fixes, and two of them were always
 * missed.
 *
 * Three reference points, following the same vocabulary: the stored posx is
 * the element's left edge, its horizontal centre, or its right edge. A width
 * of null means the element runs to the page's right margin, which is what an
 * author means by leaving it blank.
 *
 * Nothing here is clamped to the page. An element placed off the paper is an
 * authoring mistake the designer shows, and silently moving it would hide the
 * mistake while changing what a frozen snapshot renders.
 */
final class CertificateLayout
{
    public function boxFor(CertificateTemplatePage $page, CertificateTemplateElement $element): ElementBox
    {
        $width = $element->width !== null && $element->width > 0
            ? (float) $element->width
            : $this->defaultWidth($page, $element);

        $height = $element->height !== null && $element->height > 0 ? (float) $element->height : null;

        $left = match (self::horizontalOf($element->refPoint)) {
            'centre' => (float) $element->posX - ($width / 2),
            'right' => (float) $element->posX - $width,
            default => (float) $element->posX,
        };

        return ElementBox::of(
            $left,
            $this->top($element, $height),
            $width,
            $height,
            $this->textAlign($element->alignment),
        );
    }

    /**
     * The vertical reference point, which only a declared height can honour.
     *
     * An element whose height the author left blank grows to whatever its
     * content needs, and the layout service is asked for the box before the
     * renderer knows what that is. So a bottom-anchored or centred element
     * with no declared height is placed from its top edge, and that is stated
     * here rather than left as a surprise: guessing a height would move an
     * element that a frozen snapshot has already rendered somewhere else.
     */
    private function top(CertificateTemplateElement $element, ?float $height): float
    {
        $posY = (float) $element->posY;

        if ($height === null) {
            return $posY;
        }

        return match (self::verticalOf($element->refPoint)) {
            'middle' => $posY - ($height / 2),
            'bottom' => $posY - $height,
            default => $posY,
        };
    }

    private static function horizontalOf(string $refPoint): string
    {
        return match ($refPoint) {
            CertificateTemplateElement::REFPOINT_TOP_CENTER,
            CertificateTemplateElement::REFPOINT_TOP_CENTRE,
            CertificateTemplateElement::REFPOINT_CENTRE,
            CertificateTemplateElement::REFPOINT_BOTTOM_CENTRE => 'centre',
            CertificateTemplateElement::REFPOINT_TOP_RIGHT,
            CertificateTemplateElement::REFPOINT_BOTTOM_RIGHT => 'right',
            default => 'left',
        };
    }

    private static function verticalOf(string $refPoint): string
    {
        return match ($refPoint) {
            CertificateTemplateElement::REFPOINT_CENTRE => 'middle',
            CertificateTemplateElement::REFPOINT_BOTTOM_LEFT,
            CertificateTemplateElement::REFPOINT_BOTTOM_CENTRE,
            CertificateTemplateElement::REFPOINT_BOTTOM_RIGHT => 'bottom',
            default => 'top',
        };
    }

    /**
     * A page's usable width, which is what an element with no declared width
     * fills from its own left edge.
     */
    private function defaultWidth(CertificateTemplatePage $page, CertificateTemplateElement $element): float
    {
        $usable = (float) $page->width - (float) $page->marginRight;
        $remaining = match (self::horizontalOf($element->refPoint)) {
            'centre', 'right' => (float) $page->width
                - (float) $page->marginLeft
                - (float) $page->marginRight,
            default => $usable - (float) $element->posX,
        };

        return $remaining > 0.0 ? $remaining : 1.0;
    }

    private function textAlign(string $alignment): string
    {
        return match ($alignment) {
            'center', 'centre' => 'center',
            'right' => 'right',
            'justify' => 'justify',
            default => 'left',
        };
    }

    /**
     * The page's own frame, which the elements renderer draws its absolutely
     * positioned children inside.
     */
    public function pageStyle(CertificateTemplatePage $page, ?string $assetBaseUrl = null): string
    {
        $rules = [
            'position: relative',
            'box-sizing: border-box',
            'width: ' . $page->width . 'mm',
            'height: ' . $page->height . 'mm',
            'margin: 0 auto',
            'overflow: hidden',
        ];

        // The stored value is a path inside the site's own asset root and
        // never a URL. Resolved through the same resolver the elements use, an
        // absolute URL, a protocol-relative one and a data URI all answer
        // nothing, so a template author cannot turn every render and every
        // admin preview into a beacon on a third-party host. The escape stays
        // because the resolved path still lands inside a double-quoted style
        // attribute.
        $background = match (true) {
            $page->background === null => '',
            $assetBaseUrl === null => AssetPath::relative($page->background),
            default => AssetPath::resolve($page->background, $assetBaseUrl),
        };

        if ($background !== '') {
            $rules[] = 'background-image: url('
                . htmlspecialchars($background, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . ')';
            $rules[] = 'background-size: cover';
        }

        return implode('; ', $rules) . ';';
    }
}
