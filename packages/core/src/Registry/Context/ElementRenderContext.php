<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Application\Credentials\ElementBox;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;

/**
 * What an element is handed when it draws itself.
 *
 * It carries the box the layout service computed rather than the raw
 * position, so an element cannot reimplement the reference-point arithmetic
 * and cannot get it wrong. It carries resolved token values rather than a
 * resolver, so an element cannot reach a database, and it carries the purpose
 * so a preview can render a template with no certificate behind it without
 * every element having to guess whether the values it sees are real.
 */
final class ElementRenderContext
{
    public const PURPOSE_ISSUE = 'issue';
    public const PURPOSE_PREVIEW = 'preview';

    /**
     * @param array<string, string> $tokens resolved values, keyed by full placeholder name without braces
     */
    private function __construct(
        public readonly CertificateTemplatePage $page,
        public readonly ElementBox $box,
        public readonly array $tokens,
        public readonly string $locale,
        public readonly string $timezone,
        public readonly string $purpose,
        public readonly string $assetBaseUrl,
        public readonly \DateTimeImmutable $now,
    ) {
    }

    /**
     * @param array<string, string> $tokens
     */
    public static function create(
        CertificateTemplatePage $page,
        ElementBox $box,
        \DateTimeImmutable $now,
        array $tokens = [],
        string $locale = 'en-GB',
        string $timezone = 'UTC',
        string $purpose = self::PURPOSE_ISSUE,
        string $assetBaseUrl = '',
    ): self {
        return new self($page, $box, $tokens, $locale, $timezone, $purpose, $assetBaseUrl, $now);
    }

    public function isPreview(): bool
    {
        return $this->purpose === self::PURPOSE_PREVIEW;
    }

    public function token(string $name): ?string
    {
        return $this->tokens[$name] ?? null;
    }

    /**
     * Substitutes every placeholder this render holds a value for and leaves
     * the rest intact, which is the published miss direction: a visible
     * placeholder is a bug report and a blank is a silent one.
     */
    public function resolveText(string $text): string
    {
        $resolved = preg_replace_callback(
            '/\{([a-z0-9_]+\.[a-z0-9_]+)\}/',
            fn (array $matches): string => $this->tokens[$matches[1]] ?? $matches[0],
            $text,
        );

        return $resolved ?? $text;
    }

    /**
     * A stored asset path as a URL the rendered document can load.
     *
     * It resolves inside the site's own asset root and nowhere else. An
     * absolute URL used to be handed back untouched, which turned every
     * render of the certificate and every admin preview into a beacon
     * disclosing the viewer's address and user agent to whatever host a
     * template author typed, on an artefact this design treats as
     * evidentiary and privacy-bounded. A data URI is refused for the same
     * reason it is refused in stored HTML elsewhere in the product.
     *
     * A path that tries to climb out of the root resolves to nothing rather
     * than to a file above it, and the element renders empty, which is the
     * visible-failure direction the renderer already uses.
     */
    public function assetUrl(string $path): string
    {
        return AssetPath::resolve($path, $this->assetBaseUrl);
    }
}
