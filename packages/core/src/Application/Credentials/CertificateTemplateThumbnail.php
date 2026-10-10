<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;

/** Lightweight sample artwork from the current draft; no PDF worker or learner lookup. */
final class CertificateTemplateThumbnail
{
    public function __construct(private readonly CertificateRendererInterface $renderer)
    {
    }

    /**
     * @param array<string, mixed> $template
     * @return array{html: string, width: float, height: float}|null
     */
    public function render(array $template, ?CertificateTemplatePage $page): ?array
    {
        if ($page === null || $page->elements === [] || \count($page->elements) > 100) {
            return null;
        }
        try {
            $html = $this->renderer->render(CertificateRenderContext::create(
                [],
                $template,
                [
                    'site.name' => 'VidyaLMS Academy', 'user.full_name' => 'Olivia Rhye',
                    'course.title' => 'The Product Design Masterclass',
                    'certificate.issued_at' => '9 September 2026', 'certificate.code_display' => 'PREVIEW-0000',
                    'certificate.verification_url' => 'https://example.invalid/verify/PREVIEW-0000',
                ],
                (string) ($template['locale'] ?? 'en-GB'),
                'UTC',
                [$page],
                'https://example.invalid/verify/PREVIEW-0000',
                CertificateRenderContext::PURPOSE_PREVIEW,
            ));
        } catch (\Throwable) {
            // An unavailable third-party element must not hide the library's edit action.
            return null;
        }

        return ['html' => $html, 'width' => $page->width, 'height' => $page->height];
    }
}
