<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

use Shondalai\VidyaLMS\Core\Registry\Context\ElementRenderContext;

/**
 * The element draws itself onto a PDF canvas rather than being rasterised
 * from HTML.
 *
 * Default when absent: the PDF renderer rasterises the element's HTML, which
 * is correct and lossy, and is why a QR code declares this.
 *
 * The free build ships no PDF renderer, so this capability has no call site
 * in the free bytes by design: it is declared here because the signature is
 * load-bearing in two packages the day a paid renderer exists, and a
 * capability added afterwards breaks every element that already shipped.
 */
interface RendersToPdf
{
    /**
     * The vector instructions this element contributes, in the neutral shape
     * a PDF renderer consumes. Millimetres, origin at the page's top left.
     *
     * @param array<string, mixed> $payload
     *
     * @return list<array<string, mixed>>
     */
    public function pdfInstructions(array $payload, ElementRenderContext $context): array;
}
