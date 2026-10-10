<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\InspectsCertificateSource;
use Shondalai\VidyaLMS\Core\Registry\Certificate\SupportsTemplateFormats;
use Shondalai\VidyaLMS\Core\Registry\Certificate\ValidationFinding;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;

/**
 * Answers the "pdf" key on a site with no PDF port, drawing the design as the
 * free HTML certificate.
 *
 * Version 1.0.0 shipped PDF rendering in the free build on both hosts and its
 * template screens defaulted new and cloned designs to "pdf", so published
 * designs and course policies on upgraded sites name that key. From 1.0.1 the
 * PDF port comes from Pro. Without anything registered under the key,
 * issuance refused those designs, reissue answered 422, and because the
 * completion listener runs inside the event bus, which logs and swallows,
 * learners finishing a course silently received no certificate. A data
 * migration cannot repair this: Pro may be installed later, and the stored key
 * is then exactly right again.
 *
 * Rendering delegates to the elements renderer, which reads the same page and
 * element graph, so the learner receives the HTML certificate the free build
 * issues everywhere else and an existing PDF file keeps being served as it is.
 *
 * Two things keep the key from spreading. It is not offered as a choice
 * (CertificateStudio::rendererOptions() leaves it out), and it refuses
 * publication through a blocking source finding: a snapshot published here
 * would carry no PDF provenance and would fail to render the day a real PDF
 * renderer took the key back. The finding tells the author to choose the
 * Designed certificate renderer instead.
 */
final class PdfFallbackCertificateRenderer implements
    CertificateRendererInterface,
    SupportsTemplateFormats,
    InspectsCertificateSource
{
    /**
     * The key PdfCertificateRenderer registers under. Spelled out rather than
     * read from that class so a free site never has to load the PDF renderer
     * to answer for it.
     */
    public const KEY = 'pdf';

    public function __construct(
        private readonly ElementsCertificateRenderer $elements,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    /** What the learner actually receives, which is the designed HTML certificate. */
    public function label(): string
    {
        return $this->elements->label();
    }

    public function supportedTemplateFormats(): array
    {
        return $this->elements->supportedTemplateFormats();
    }

    public function render(CertificateRenderContext $context): string
    {
        return $this->elements->render($context);
    }

    public function sourceFindings(array $template, array $pages): array
    {
        return [
            ValidationFinding::error('pdf.unavailable', 'vidyalms.certificate_pdf.unsupported', [
                'reason' => $this->translator->translate('vidyalms.certificate_pdf.reason.unavailable'),
            ]),
        ];
    }

    /** Never frozen: publication is refused before a snapshot is written. */
    public function renderProvenance(): array
    {
        return [];
    }

    /** Never frozen: publication is refused before a snapshot is written. */
    public function prepareSource(array $template, array $pages): array
    {
        return [];
    }
}
