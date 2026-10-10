<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Renders an issued certificate from a template. The free build ships one
 * HTML renderer and one default template; the Pro designer adds templates
 * and richer rendering. PDF generation happens in a scheduled job that
 * stores the file through the Storage port.
 *
 * It takes a context object rather than three bare arrays. It was the only
 * provider method in the core without one, so it is corrected now rather than
 * after release: a later field is additive on a context and a fourth
 * parameter breaks every renderer that exists. Binary output stays a
 * capability (RendersToBinary) rather than a return type change, because a
 * string return cannot express a PDF and abusing a return type is how a
 * contract rots.
 */
interface CertificateRendererInterface extends ProviderInterface
{
    public function label(): string;

    public function render(CertificateRenderContext $context): string;
}
