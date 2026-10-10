<?php

declare(strict_types=1);

/**
 * VidyaLMS: public uploads and external private storage.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety;
use Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalUploadQuota;
use Shondalai\VidyaLMS\Core\Upload\UploadStorage;
use Shondalai\VidyaLMS\WordPress\Files\PrivateStorageOwnership;
use Shondalai\VidyaLMS\WordPress\Files\UploadLimits;

defined('ABSPATH') || exit;

/**
 * Public assets live under uploads/vidyalms. Private I/O requires an absolute
 * VIDYALMS_PRIVATE_DIR chosen in server configuration, outside ABSPATH, the
 * uploads/content roots and the web server's document root. Database settings
 * never select a filesystem target. The historical in-uploads default can
 * still be reported by privateRoot(), but every private read, write and path
 * resolution fails closed there, before directory or guard creation.
 *
 * Deny files and index.html are defense in depth, written only after the root
 * passes those checks. Their interpretation by Apache, IIS or Nginx is not the
 * confidentiality boundary. Administrators must not expose the external root
 * through a separate server alias. Symbolic links and filesystem aliases in
 * storage paths are refused by the shared filesystem boundary.
 *
 * The download endpoint validates signed, expiring URLs and then obtains a
 * checked privatePath(). Uploads additionally use the shared, locked per-user
 * quota across public and private files. Writes publish complete temporary
 * files atomically and remove their temporary files on failure.
 *
 * Multisite uses <configured base>/sites/<blog id>; single-site keeps the base.
 * An empty external root is claimed with a versioned, site-token-bound marker.
 * Existing nonempty unmarked roots require explicit server-owner migration.
 * Invalid or foreign markers fail closed. Ownership and resolution
 * are shared with lifecycle through the standalone PrivateStorageOwnership class.
 *
 * privateRoot() remains a location resolver for diagnostics and lifecycle
 * cleanup, which applies its own deletion checks. webExposedUrl() reports only
 * an uploads mapping; it is not evidence that another server alias is absent.
 */
final class WpStorage implements StoragePort, \Shondalai\VidyaLMS\Core\Port\Storage\ReadsPublicFiles, UploadStorage, \Shondalai\VidyaLMS\Core\Port\Storage\ListsLearnerUploads
{
    use \Shondalai\VidyaLMS\Core\Infrastructure\Storage\LearnerUploadListing;
    public const PUBLIC_FOLDER = 'vidyalms';

    public const PRIVATE_FOLDER = 'vidyalms-private';

    /**
     * The admin-post action the download endpoint registers. Signed URLs point
     * at admin-post.php, which runs for logged out visitors too through the
     * matching admin_post_nopriv_ action, because a certificate download link
     * is followed by whoever was sent it.
     */
    public const DOWNLOAD_ACTION = 'vidyalms_file';

    public const QUERY_PATH = 'vidyalms_path';

    public const QUERY_EXPIRES = 'vidyalms_expires';

    public const QUERY_SIGNATURE = 'vidyalms_signature';

    /**
     * Separates this signature's domain from any other HMAC keyed on the same
     * salt, so a signature minted here cannot be replayed elsewhere.
     */
    private const SIGNATURE_CONTEXT = 'vidyalms.file';

    /** @var array{network_id: int, blog_id: int, site_url: string, installation: string} */
    private readonly array $siteIdentity;
    private readonly string $resolvedPrivateRoot;

    private const DENY_HTACCESS = "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";

    private const DENY_WEBCONFIG = '<?xml version="1.0" encoding="utf-8"?>
<configuration>
    <system.webServer>
        <security>
            <requestFiltering>
                <hiddenSegments>
                    <add segment="." />
                </hiddenSegments>
            </requestFiltering>
        </security>
        <authorization>
            <deny users="*" />
        </authorization>
    </system.webServer>
</configuration>
';

    /**
     * @param string $privateFolder historical default (private I/O fails closed),
     *                              or the server-owned external VIDYALMS_PRIVATE_DIR base
     */
    public function __construct(
        private readonly string $baseDir,
        private readonly string $baseUrl,
        private readonly string $publicFolder = self::PUBLIC_FOLDER,
        private readonly string $privateFolder = self::PRIVATE_FOLDER,
    ) {
        $this->siteIdentity = PrivateStorageOwnership::siteIdentity();
        $this->resolvedPrivateRoot = self::resolvePrivateRoot($this->baseDir, $this->privateFolder);
    }

    public function putPublic(string $path, string $contents): void
    {
        LocalStorageSafety::write($this->publicPath($path), $contents, false);
    }

    public function readPublic(string $path, int $maximumBytes): string
    {
        $this->assertCurrentSite();
        return \Shondalai\VidyaLMS\WordPress\Files\BoundedPublicFile::read(
            $this->baseDir . '/' . trim($this->publicFolder, '/'),
            $path,
            $maximumBytes,
        );
    }

    public function putPrivate(string $path, string $contents): void
    {
        $this->ensurePrivateRoot();
        $this->write($this->privatePath($path), $contents);
    }

    public function putUpload(string $path, string $contents, bool $public, int $userId): void
    {
        // Authoring uploads already require teach/manage. Learner quotas must
        // not override the separately configured course-media size limits.
        if (!preg_match('~^(?:attempts|submissions)/~', $path)) {
            $public ? $this->putPublic($path, $contents) : $this->putPrivate($path, $contents);
            return;
        }
        if ($public) {
            $quotaRoot = $this->publicRoot();
            if (!wp_mkdir_p($quotaRoot)) {
                throw new \RuntimeException('Cannot create public upload storage.');
            }
            $roots = [$quotaRoot];
        } else {
            $this->ensurePrivateRoot();
            $quotaRoot = $this->privateRoot();
            $roots = [$quotaRoot, $this->publicRoot()];
        }
        $limits = UploadLimits::learnerQuota($userId);
        (new LocalUploadQuota($quotaRoot, $roots, $limits['max_bytes'], $limits['max_files'], $limits['max_hourly_files']))->store(
            $userId,
            strlen($contents),
            fn () => $public ? $this->putPublic($path, $contents) : $this->putPrivate($path, $contents),
        );
    }

    public function readPrivate(string $path): string
    {
        $absolute = $this->privatePath($path);
        $contents = is_file($absolute) ? file_get_contents($absolute) : false;

        if ($contents === false) {
            // Exception arguments are data; escape at the HTTP/template boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \RuntimeException(sprintf('Private file "%s" does not exist.', $path));
        }

        return $contents;
    }

    public function exists(string $path): bool
    {
        return is_file($this->privatePath($path)) || is_file($this->publicPath($path));
    }

    public function delete(string $path): void
    {
        foreach ([$this->privatePath($path), $this->publicPath($path)] as $absolute) {
            if (is_file($absolute)) {
                wp_delete_file($absolute);
            }
        }
    }

    public function publicUrl(string $path): string
    {
        $this->assertCurrentSite();
        return rtrim($this->baseUrl, '/') . '/' . trim($this->publicFolder, '/') . '/' . self::normalise($path);
    }

    public function signedUrl(string $path, int $expiresInSeconds): string
    {
        $this->assertCurrentSite();
        $relative = LocalStorageSafety::downloadable($path);
        $expires = time() + max(1, $expiresInSeconds);

        return add_query_arg(
            [
                'action' => self::DOWNLOAD_ACTION,
                self::QUERY_PATH => rawurlencode($relative),
                self::QUERY_EXPIRES => (string) $expires,
                self::QUERY_SIGNATURE => self::sign($relative, $expires),
            ],
            admin_url('admin-post.php'),
        );
    }

    /**
     * Validates a signed download request. False for a tampered signature, an
     * expired link or a path that escapes the private root.
     *
     * The download endpoint must serve exactly the path this returned true for,
     * never one derived from it.
     */
    public static function verifySignature(string $path, int $expires, string $signature): bool
    {
        if ($expires < time() || $signature === '') {
            return false;
        }

        try {
            $relative = LocalStorageSafety::downloadable($path);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return hash_equals(self::sign($relative, $expires), $signature);
    }

    /**
     * Keyed on wp_salt(), so the signature dies with the site's salts and is
     * not derivable from anything a visitor can see.
     */
    public static function sign(string $path, int $expires): string
    {
        $site = PrivateStorageOwnership::siteIdentity();
        return hash_hmac(
            'sha256',
            self::SIGNATURE_CONTEXT . '|' . $site['network_id'] . '|' . $site['blog_id'] . '|' . $path . '|' . $expires,
            wp_salt('auth'),
        );
    }

    public function privateRoot(): string
    {
        return $this->resolvedPrivateRoot;
    }

    public static function resolvePrivateRoot(string $uploadsBase, string $configured): string
    {
        return PrivateStorageOwnership::resolveRoot($uploadsBase, $configured);
    }

    /** False for an unmarked root; malformed or foreign ownership throws. */
    public function ownsPrivateRoot(): bool
    {
        return PrivateStorageOwnership::isOwned($this->safePrivateRoot());
    }

    /**
     * The URL the uploads directory would serve the private root at, or null
     * when the private root is not under uploads and so has no uploads URL at
     * all. Null is not on its own proof that nothing serves the directory: an
     * absolute path that still sits inside the document root has a URL, just
     * not one this class can compute.
     */
    public function webExposedUrl(): ?string
    {
        $root = $this->privateRoot();
        $base = rtrim(str_replace('\\', '/', $this->baseDir), '/');

        if ($root === $base || !str_starts_with($root . '/', $base . '/')) {
            return null;
        }

        return rtrim($this->baseUrl, '/') . '/' . trim(substr($root, \strlen($base)), '/');
    }

    public function publicRoot(): string
    {
        $this->assertCurrentSite();
        $root = rtrim($this->baseDir, '/\\') . '/' . trim($this->publicFolder, '/');
        // Even a misconfigured WordPress uploads option may not target core code.
        return LocalStorageSafety::privateRoot($root, [ABSPATH . 'wp-admin', ABSPATH . 'wp-includes']);
    }

    public function privatePath(string $path): string
    {
        if (strtolower(explode('/', $path)[0]) === PrivateStorageOwnership::MARKER) {
            throw new \InvalidArgumentException('The private storage ownership marker is reserved.');
        }
        return LocalStorageSafety::path($this->safePrivateRoot(), $path);
    }

    public function publicPath(string $path): string
    {
        return LocalStorageSafety::path($this->publicRoot(), $path);
    }

    /**
     * Creates the private area and its guards. Called on activation as well as
     * before the first private write, because a site that never uploads
     * anything should still not have an unguarded directory appear later.
     */
    public function ensurePrivateRoot(): void
    {
        $root = $this->safePrivateRoot();

        if (!wp_mkdir_p($root)) {
            throw new \RuntimeException(sprintf(
                'Cannot create the private storage folder "%s". Point VIDYALMS_PRIVATE_DIR at a writable location, '
                . 'outside the document root.',
                // Exception arguments are data; escape at the HTTP/template boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                $root
            ));
        }

        if (!wp_is_writable($root)) {
            throw new \RuntimeException(sprintf(
                'The private storage folder "%s" is not writable. Point VIDYALMS_PRIVATE_DIR at a writable location, '
                . 'outside the document root.',
                // Exception arguments are data; escape at the HTTP/template boundary.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
                $root
            ));
        }

        if (!PrivateStorageOwnership::claimIfEmpty($root)) {
            throw new \RuntimeException('Private storage ownership must be verified before writing files.');
        }

        $this->writeGuard($root . '/.htaccess', self::DENY_HTACCESS);
        $this->writeGuard($root . '/web.config', self::DENY_WEBCONFIG);
        $this->writeGuard($root . '/index.html', '');
    }

    private function safePrivateRoot(): string
    {
        $this->assertCurrentSite();
        $root = LocalStorageSafety::privateRoot($this->privateRoot(), [
            ABSPATH, $this->baseDir,
            defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content',
            defined('WP_PLUGIN_DIR') ? WP_PLUGIN_DIR : '',
            defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : '',
            // OS document root is boundary data, not display text; LocalStorageSafety validates the real path.
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            wp_unslash((string) ($_SERVER['DOCUMENT_ROOT'] ?? '')),
        ]);
        PrivateStorageOwnership::assertOwnedOrEmpty($root);
        return $root;
    }

    private function assertCurrentSite(): void
    {
        if ($this->siteIdentity !== PrivateStorageOwnership::siteIdentity()) {
            throw new \RuntimeException('A storage instance cannot be reused after switching WordPress sites.');
        }
    }

    private function writeGuard(string $absolute, string $contents): void
    {
        if (!is_file($absolute)) {
            LocalStorageSafety::write($absolute, $contents);
        }
    }

    private function write(string $absolute, string $contents): void
    {
        LocalStorageSafety::write($absolute, $contents);
    }

    /**
     * Path validation happens before any filesystem call, not after. Rejecting
     * rather than resolving is deliberate: realpath() on a path that does not
     * exist yet returns false, so a write would have no check at all.
     */
    private static function normalise(string $path): string
    {
        return LocalStorageSafety::relative($path);
    }
}
