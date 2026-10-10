<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The freeze, and the thaw.
 *
 * This is the single most important decision in the credentials area. At
 * issue, the resolved template, its pages, its elements and every resolved
 * token value are frozen onto the row; rendering reads the snapshot and never
 * the live template. Correcting a template therefore cannot rewrite a
 * certificate somebody already holds, and there is a record of exactly what
 * the holder was given.
 *
 * No mainstream platform solves this. Moodle's custom certificate issue row
 * stores a user id, a template id, a code, two emailed flags and a timestamp,
 * and regenerates the PDF from the live template for ever; LearnDash renders
 * from current post content. The pattern needs no new vocabulary here,
 * because the assessment engine already proves it with the frozen attempt
 * manifest.
 *
 * Both documents go through the StoredShape envelope, so a snapshot written
 * by one version is always readable as what wrote it.
 */
final class TemplateSnapshot
{
    /** The grammar version of the snapshot document itself. */
    public const VERSION = 1;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>          $template the certificate_templates row
     * @param list<CertificateTemplatePage> $pages
     *
     * @return array<string, mixed>
     */
    public static function freezeTemplate(array $template, array $pages): array
    {
        return StoredShape::wrap([
            'id' => (int) ($template['id'] ?? 0),
            'template_key' => $template['template_key'] ?? null,
            'name' => $template['name'] ?? '',
            'category' => $template['category'] ?? null,
            'renderer' => \is_string($template['renderer'] ?? null) ? $template['renderer'] : 'html',
            'format' => \is_string($template['format'] ?? null) ? $template['format'] : 'html',
            'achievement_type' => \is_string($template['achievement_type'] ?? null)
                ? $template['achievement_type']
                : 'CertificateOfCompletion',
            'locale' => $template['locale'] ?? null,
            'revision' => (int) ($template['revision'] ?? 1),
            'schema_version' => (int) ($template['schema_version'] ?? 1),
            // The body is frozen too, and not only the pages: the html format
            // renders from it, and a template corrected from under an issued
            // certificate is exactly what this exists to prevent.
            'body' => \is_string($template['body'] ?? null) ? $template['body'] : '',
            'expiry_mode' => $template['expiry_mode'] ?? 'never',
            'expiry_days' => isset($template['expiry_days']) ? (int) $template['expiry_days'] : null,
            'expiry_at' => $template['expiry_at'] ?? null,
            'render_provenance' => $template['render_provenance'] ?? null,
            'render_assets' => $template['render_assets'] ?? null,
            'course_policy' => $template['course_policy'] ?? null,
            'pages' => array_map(
                static fn (CertificateTemplatePage $page): array => $page->toSnapshot(),
                $pages,
            ),
        ], self::VERSION);
    }

    /**
     * @param array<string, string> $tokens
     * @param array<string, string> $issuer name, url and image as configured at issue
     *
     * @return array<string, mixed>
     */
    public static function freezeContext(
        array $tokens,
        string $locale,
        string $timezone,
        string $verificationUrl,
        array $issuer = [],
    ): array {
        return StoredShape::wrap([
            'tokens' => $tokens,
            'locale' => $locale,
            'timezone' => $timezone,
            'verification_url' => $verificationUrl,
            // Frozen because it is inside the credential document a wallet
            // keeps. An issuer settings edit in 2028 must not change what a
            // credential issued in 2026 says about who issued it.
            'issuer' => $issuer,
        ], self::VERSION);
    }

    /**
     * The template row a renderer sees, taken out of the envelope.
     *
     * @param array<string, mixed> $snapshot
     *
     * @return array<string, mixed>
     */
    public static function templateOf(array $snapshot): array
    {
        return StoredShape::bodyOf($snapshot);
    }

    /**
     * @param array<string, mixed> $snapshot
     *
     * @return list<CertificateTemplatePage>
     */
    public static function pagesOf(array $snapshot): array
    {
        $body = StoredShape::bodyOf($snapshot);
        $pages = $body['pages'] ?? [];

        if (!\is_array($pages)) {
            return [];
        }

        $frozen = [];

        foreach ($pages as $page) {
            if (\is_array($page)) {
                /** @var array<string, mixed> $page */
                $frozen[] = CertificateTemplatePage::fromSnapshot($page);
            }
        }

        return $frozen;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     *
     * @return array<string, string>
     */
    public static function tokensOf(?array $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }

        $body = StoredShape::bodyOf($snapshot);
        $tokens = $body['tokens'] ?? [];

        if (!\is_array($tokens)) {
            return [];
        }

        $values = [];

        foreach ($tokens as $name => $value) {
            if (\is_string($name) && \is_string($value)) {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * The issuer as it stood at issue, or an empty map for a row frozen
     * before the issuer was part of the freeze.
     *
     * @param array<string, mixed>|null $snapshot
     *
     * @return array<string, string>
     */
    public static function issuerOf(?array $snapshot): array
    {
        if ($snapshot === null) {
            return [];
        }

        $issuer = StoredShape::bodyOf($snapshot)['issuer'] ?? [];

        if (!\is_array($issuer)) {
            return [];
        }

        $values = [];

        foreach ($issuer as $name => $value) {
            if (\is_string($name) && \is_string($value) && $value !== '') {
                $values[$name] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, mixed>|null $snapshot
     */
    public static function stringOf(?array $snapshot, string $key, string $default = ''): string
    {
        if ($snapshot === null) {
            return $default;
        }

        $value = StoredShape::bodyOf($snapshot)[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : $default;
    }
}
