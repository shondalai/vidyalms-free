<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;

/**
 * One sheet of paper with the elements drawn on it, in draw order.
 *
 * The page and its elements travel together because the designer needs both
 * to draw anything, and two round trips would let a page arrive without its
 * elements.
 */
final class CertificateTemplatePageView
{
    /**
     * @param list<CertificateTemplateElementView> $elements in draw order
     */
    public function __construct(
        public readonly CertificateTemplatePage $page,
        public readonly array $elements,
    ) {
    }
}
