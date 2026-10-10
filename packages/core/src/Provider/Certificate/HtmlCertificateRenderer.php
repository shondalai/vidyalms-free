<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Certificate;

use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Certificate\CertificateRendererInterface;
use Shondalai\VidyaLMS\Core\Registry\Certificate\SupportsTemplateFormats;
use Shondalai\VidyaLMS\Core\Registry\Context\CertificateRenderContext;

/**
 * The free build's renderer: replaces {placeholder} tokens in the template
 * body with HTML-escaped context values. Unknown placeholders stay intact so
 * a template written for a richer renderer degrades visibly, not silently.
 */
final class HtmlCertificateRenderer implements CertificateRendererInterface, SupportsTemplateFormats
{
    public function supportedTemplateFormats(): array
    {
        return ['html'];
    }

    public function __construct(
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return 'html';
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.certificate_renderer.html');
    }

    public function render(CertificateRenderContext $context): string
    {
        $body = $context->body();

        $rendered = preg_replace_callback(
            '/\{([a-z0-9_.]+)\}/',
            static function (array $matches) use ($context): string {
                $value = $context->token($matches[1]);

                if ($value === null) {
                    return $matches[0];
                }

                return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $body,
        );

        return self::dropUnresolvedImages($rendered ?? $body);
    }

    /**
     * An image whose source is still a placeholder is removed rather than
     * emitted.
     *
     * Leaving a token intact is the right miss direction inside prose: the
     * author sees which placeholder the site cannot answer. Inside an img
     * src it is a broken image on an evidentiary artefact, and the seeded
     * free template carries exactly one, the site logo, which no shipped
     * install could answer until the site configured one. The rule is stated
     * for every img rather than for that one, because the same failure is
     * available to any template that names an unresolvable asset token.
     */
    private static function dropUnresolvedImages(string $html): string
    {
        $stripped = preg_replace(
            '/<img\b[^>]*\bsrc\s*=\s*(["\'])[^"\']*\{[a-z0-9_.]+\}[^"\']*\1[^>]*>/i',
            '',
            $html,
        );

        return $stripped ?? $html;
    }
}
