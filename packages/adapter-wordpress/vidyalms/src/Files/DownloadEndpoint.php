<?php

declare(strict_types=1);

/**
 * VidyaLMS: the endpoint that serves a signed private file.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Files;

use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use Shondalai\VidyaLMS\WordPress\Plugin;
use Shondalai\VidyaLMS\WordPress\Port\WpStorage;

defined('ABSPATH') || exit;

/**
 * The other half of WpStorage::signedUrl().
 *
 * An assignment submission, a rendered certificate or an import package is
 * meant to reach a person only through a link this endpoint serves. The
 * signature is the authorization: it is an HMAC over the path and the expiry
 * keyed on the site's auth salt, and WpStorage minted it for someone the use
 * cases had already allowed. Whether a direct request to the file could bypass
 * this endpoint depends on where the private root sits and on the web server,
 * which WpStorage's own docblock sets out; this endpoint is the intended path
 * either way, not the only conceivable one.
 *
 * That is why there is no capability check and no nonce here, and why the
 * nopriv action is registered as well. A certificate link is followed by
 * whoever the holder sent it to, often with no account on the site at all, and
 * a nonce is bound to a session that visitor does not have. Adding a
 * capability check would break exactly the case the signed URL exists for.
 *
 * The path that is streamed is the one verifySignature() returned true for,
 * never one derived from it afterwards: deriving would let a path that passed
 * the signature check turn into a different path before the read.
 */
final class DownloadEndpoint
{
    /**
     * Sent in chunks rather than read whole: a SCORM package or a video is
     * larger than the memory limit of a shared host.
     */
    private const CHUNK_BYTES = 262144;

    private function __construct()
    {
    }

    public static function register(): void
    {
        add_action('admin_post_' . WpStorage::DOWNLOAD_ACTION, [self::class, 'serve']);
        add_action('admin_post_nopriv_' . WpStorage::DOWNLOAD_ACTION, [self::class, 'serve']);
    }

    public static function serve(): void
    {
        // The three query members are the signed message itself. They are read
        // raw and validated by shape, then the HMAC decides: sanitize_text_field
        // would silently rewrite the very bytes the signature covers, and a
        // nonce check would refuse the visitor this link exists for.
        //
        // The path is NOT decoded here. WpStorage rawurlencodes it once and
        // add_query_arg passes new arguments through untouched (it re-encodes
        // only members that were already in the URL's query string), so PHP has
        // already undone the single layer on the way into $_GET. Decoding again
        // would corrupt any path holding a literal percent sign and refuse a
        // link that is perfectly valid.
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $path = isset($_GET[WpStorage::QUERY_PATH]) && \is_string($_GET[WpStorage::QUERY_PATH])
            ? (string) wp_unslash($_GET[WpStorage::QUERY_PATH]) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : '';
        $expires = isset($_GET[WpStorage::QUERY_EXPIRES]) && is_scalar($_GET[WpStorage::QUERY_EXPIRES])
            ? (int) $_GET[WpStorage::QUERY_EXPIRES] // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : 0;
        $signature = isset($_GET[WpStorage::QUERY_SIGNATURE]) && \is_string($_GET[WpStorage::QUERY_SIGNATURE])
            ? (string) preg_replace('/[^a-f0-9]/', '', wp_unslash($_GET[WpStorage::QUERY_SIGNATURE])) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended

        if (!WpStorage::verifySignature($path, $expires, $signature)) {
            self::refuse(403, __('This download link is not valid, or it has expired.', 'vidyalms'));
        }

        try {
            $absolute = Plugin::storage()->privatePath($path);
        } catch (\Throwable) {
            self::refuse(400, __('That file cannot be addressed.', 'vidyalms'));
        }

        if (!is_file($absolute) || !is_readable($absolute)) {
            self::refuse(404, __('That file is no longer available.', 'vidyalms'));
        }

        self::stream($absolute, basename($path));
    }

    /**
     * The credential media types, which WordPress does not know.
     *
     * A signed download is how a learner takes a credential away, and
     * wp_check_filetype answers only for extensions the site allows as
     * uploads, which none of these are. Returning the empty string means "no
     * opinion" and leaves the WordPress answer, and after it the
     * application/octet-stream fallback, in charge.
     *
     * html and htm are deliberately not here. The free certificate artefact is
     * an HTML document assembled from a site-authored template, and naming it
     * text/html would run any script it carries on this origin; it streams as
     * an attachment of unknown type instead, and the readable, accessible
     * artefact is the verification page rather than the file.
     */
    private static function credentialMediaType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // html and htm never reach the core table's answer, for the reason
        // above: naming the free certificate artefact text/html would run any
        // script a site-authored template carries, on this origin.
        if ($extension === 'html' || $extension === 'htm') {
            return '';
        }

        if ($extension === 'jsonld') {
            return 'application/ld+json';
        }

        // The product's own table, in the package that owns the artefact
        // shape, rather than a fifth private copy of part of it: this one
        // knew five types and the Joomla mount knew twenty-four, so the same
        // baked SVG credential downloaded as image/svg+xml there and as an
        // unknown blob here. A format that registers is served on both
        // mounts the day it does.
        return RenderedArtefact::mediaTypeFor($extension) ?? '';
    }

    /**
     * Content-Disposition is always "attachment" and the type is taken from the
     * extension WordPress recognises, never from the file's own bytes: a
     * learner-uploaded submission served inline as text/html would run as a
     * script on this origin.
     */
    private static function stream(string $absolute, string $filename): void
    {
        $size = filesize($absolute);
        $mime = self::credentialMediaType($filename);

        if ($mime === '') {
            $type = wp_check_filetype($filename);
            $mime = \is_string($type['type'] ?? null) && $type['type'] !== ''
                ? $type['type']
                : 'application/octet-stream';
        }

        nocache_headers();

        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('X-Content-Type-Options: nosniff');
        header('X-Robots-Tag: noindex, nofollow', true);

        if ($size !== false) {
            header('Content-Length: ' . $size);
        }

        // Anything already buffered would be prepended to the file body.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Streams an authorized private download with bounded memory; WP_Filesystem reads the entire file.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $handle = fopen($absolute, 'rb');

        if ($handle === false) {
            self::refuse(404, __('That file is no longer available.', 'vidyalms'));
        }

        while (!feof($handle)) {
            // Streams an authorized private download with bounded memory; WP_Filesystem reads the entire file.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
            $chunk = fread($handle, self::CHUNK_BYTES);

            if ($chunk === false) {
                break;
            }

            echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

            flush();
        }

        // Streams an authorized private download with bounded memory; WP_Filesystem reads the entire file.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose($handle);

        exit;
    }

    private static function refuse(int $status, string $message): never
    {
        status_header($status);
        nocache_headers();

        // wp_die() never returns, which is what makes this method "never".
        wp_die(
            esc_html($message),
            esc_html__('Download refused', 'vidyalms'),
            ['response' => (int) $status, 'back_link' => false]
        );
    }
}
