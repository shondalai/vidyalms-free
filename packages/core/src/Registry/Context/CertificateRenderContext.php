<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;

/**
 * What a certificate renderer is handed.
 *
 * The method took three bare arrays and was the only provider method in the
 * core without a context object, which is why it was corrected before any
 * designer existed: a later field is additive on a context object and a
 * fourth array parameter breaks every renderer that already exists.
 *
 * It carries the frozen template snapshot rather than the live template, and
 * that is the whole point of the area: rendering reads what was resolved at
 * issue, so correcting a template cannot rewrite a credential somebody
 * already holds. A context built with no snapshot is a row that predates the
 * change; it renders from the live template and the response says so, which
 * is how an administrator sees which certificates are unfrozen instead of
 * discovering it later.
 *
 * The purpose is here because a preview has no certificate behind it: without
 * it, every renderer and every element has to guess whether the values it
 * sees are real, and a preview that writes anything is a preview that has
 * issued a credential.
 *
 * The members are named rather than positional for the same reason the
 * assessment presentation context is: a renderer that needs the locale should
 * not have to guess which array it arrived in.
 */
final class CertificateRenderContext
{
    public const PURPOSE_ISSUE = 'issue';
    public const PURPOSE_PREVIEW = 'preview';

    /**
     * @param array<string, mixed>             $certificate the certificates row
     * @param array<string, mixed>             $template    the frozen template, or the live row when unfrozen
     * @param array<string, mixed>             $tokens      the resolved token values, keyed without braces
     * @param list<CertificateTemplatePage>    $pages       the frozen pages and their elements
     */
    private function __construct(
        public readonly array $certificate,
        public readonly array $template,
        public readonly array $tokens,
        public readonly string $locale,
        public readonly string $timezone,
        public readonly array $pages,
        public readonly string $verificationUrl,
        public readonly string $purpose,
        public readonly bool $fromSnapshot,
        public readonly string $assetBaseUrl,
        public readonly \DateTimeImmutable $now,
    ) {
    }

    /**
     * @param array<string, mixed>          $certificate
     * @param array<string, mixed>          $template
     * @param array<string, mixed>          $tokens
     * @param list<CertificateTemplatePage> $pages
     */
    public static function create(
        array $certificate,
        array $template,
        array $tokens = [],
        string $locale = 'en-GB',
        string $timezone = 'UTC',
        array $pages = [],
        string $verificationUrl = '',
        string $purpose = self::PURPOSE_ISSUE,
        bool $fromSnapshot = false,
        string $assetBaseUrl = '',
        ?\DateTimeImmutable $now = null,
    ): self {
        return new self(
            $certificate,
            $template,
            $tokens,
            $locale,
            $timezone,
            $pages,
            $verificationUrl,
            $purpose,
            $fromSnapshot,
            $assetBaseUrl,
            $now ?? new \DateTimeImmutable('@0'),
        );
    }

    /**
     * One resolved token, or null when the render holds no value for it. A
     * renderer leaves an unresolved placeholder intact rather than blanking
     * it, because a visible placeholder is a bug report and a blank is a
     * silent one.
     */
    public function token(string $name): ?string
    {
        $value = $this->tokens[$name] ?? null;

        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value) || \is_bool($value)) {
            return (string) $value;
        }

        return $value instanceof \Stringable ? (string) $value : null;
    }

    public function hasToken(string $name): bool
    {
        return \array_key_exists($name, $this->tokens);
    }

    /**
     * The template's authored body, which is what the free html renderer
     * substitutes into.
     */
    public function body(): string
    {
        $body = $this->template['body'] ?? null;

        return \is_string($body) ? $body : '';
    }

    /**
     * html or elements. The discriminator is explicit so the renderer never
     * has to guess whether the body holds markup or the pages hold elements.
     */
    public function format(): string
    {
        $format = $this->template['format'] ?? null;

        return \is_string($format) && $format !== '' ? $format : 'html';
    }

    public function isPreview(): bool
    {
        return $this->purpose === self::PURPOSE_PREVIEW;
    }

    /**
     * @return array<string, string>
     */
    public function stringTokens(): array
    {
        $strings = [];

        foreach (array_keys($this->tokens) as $name) {
            $value = $this->token($name);

            if ($value !== null) {
                $strings[$name] = $value;
            }
        }

        return $strings;
    }
}
