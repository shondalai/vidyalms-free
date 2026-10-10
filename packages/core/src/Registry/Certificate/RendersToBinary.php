<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;

/**
 * The renderer produces bytes rather than an HTML document.
 *
 * Default when absent: the HTML string the base render() returns, stored as
 * text/html. The call site is RenderCertificate::renderArtefact(), which
 * exists precisely so a paid renderer implementing this is actually called:
 * without it a PDF renderer's bytes would land on an .html path with the
 * wrong media type and nothing would say so.
 */
interface RendersToBinary
{
    public function renderBinary(CertificateRenderContext $context): RenderedArtefact;
}
