<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Uri\Uri;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalStorageSafety;
use Shondalai\VidyaLMS\Core\Infrastructure\Storage\LocalUploadQuota;
use Shondalai\VidyaLMS\Core\Upload\UploadStorage;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\UploadLimits;

/**
 * Public assets live under images/vidyalms. Private I/O requires the server's
 * VIDYALMS_PRIVATE_DIR outside JPATH_ROOT and the known document roots. Runtime
 * settings cannot select the directory. The historical in-web-root default
 * remains resolvable for diagnostics, but private reads and writes fail closed
 * before creating directories or guards. Deny files are defense in depth only.
 *
 * The same server constant must be loaded by web, API and scheduled workers.
 * An unavailable or unsafe root fails instead of choosing a different location.
 * Symlinks and filesystem aliases are rejected, uploads share locked cumulative
 * limits, and complete files are published atomically. Site owners must keep
 * the external root out of all additional virtual-host aliases.
 *
 * @since 1.0.0
 */
final class JoomlaStorage implements StoragePort, \Shondalai\VidyaLMS\Core\Port\Storage\ReadsPublicFiles, UploadStorage, \Shondalai\VidyaLMS\Core\Port\Storage\ListsLearnerUploads
{
    use \Shondalai\VidyaLMS\Core\Infrastructure\Storage\LearnerUploadListing;
    public const PUBLIC_FOLDER = 'images/vidyalms';

    /** Historical location for diagnostics; private I/O refuses this default. */
    public const PRIVATE_FOLDER = 'media/com_vidyalms/private';

    public const DOWNLOAD_TASK = 'file.download';

    private const SIGNATURE_CONTEXT = 'vidyalms.file';

    private const DENY_HTACCESS = "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";

    private const DENY_WEBCONFIG = <<<'XML'
        <?xml version="1.0" encoding="utf-8"?>
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

        XML;

    /**
     * @param string      $root            JPATH_ROOT
     * @param string      $privateLocation absolute, or relative to $root
     * @param string|null $documentRoot    the directory the web server maps to
     *                                     "/", or null when the site's
     *                                     configuration does not say
     */
    public function __construct(
        private readonly string $root,
        private readonly string $privateLocation = self::PRIVATE_FOLDER,
        private readonly ?string $documentRoot = null,
    ) {
    }

    /** Resolve the deployment constant, never a manager-editable setting. */
    public static function resolvePrivateRoot(string $siteRoot, string $configured): string
    {
        $siteRoot   = self::canonical($siteRoot);
        $configured = trim(str_replace('\\', '/', $configured));

        if ($configured !== '') {
            return LocalStorageSafety::absolute($configured);
        }

        return $siteRoot . '/' . self::PRIVATE_FOLDER;
    }

    /**
     * The directory the web server maps to "/", or null when the site's
     * configuration does not say.
     *
     * $liveSite is the configured site address: empty on most sites, and
     * "https://host/joomla" on one that says where it lives. It reports the
     * layout, it does not choose the private root; webExposedPath() is the
     * only thing downstream of it, and being wrong there costs a screen an
     * accurate description rather than costing the queue its file.
     *
     * A path that does not line up with the site root's own tail is a
     * misconfiguration rather than an invitation to guess, and is answered
     * null like an empty one.
     */
    public static function documentRoot(string $siteRoot, ?string $liveSite): ?string
    {
        $siteRoot = self::canonical($siteRoot);
        $address  = trim((string) $liveSite);

        if ($address === '') {
            return null;
        }

        $path = parse_url($address, \PHP_URL_PATH);
        $segments = array_values(array_filter(
            explode('/', trim(str_replace('\\', '/', \is_string($path) ? $path : ''), '/')),
            static fn (string $segment): bool => $segment !== '',
        ));

        $current = $siteRoot;

        foreach (array_reverse($segments) as $segment) {
            if (!self::sameSegment(basename($current), $segment)) {
                return null;
            }

            $parent = self::canonical(\dirname($current));

            if ($parent === $current) {
                return null;
            }

            $current = $parent;
        }

        return $current;
    }

    public function putPublic(string $path, string $contents): void
    {
        LocalStorageSafety::write($this->publicPath($path), $contents, false);
    }

    /**
     * Bounded bytes of one public file, for a renderer that embeds site images.
     *
     * The rules are the PDF runtime's BoundedPublicFile, held here because the
     * runtime ships only with VidyaLMS Pro and this port is the free build's: a
     * storage contract must not answer differently depending on which package
     * happens to be installed. The path must stay inside the exact public root
     * after symlinks resolve, and a file over the limit is refused before it is
     * read rather than truncated.
     */
    public function readPublic(string $path, int $maximumBytes): string
    {
        $segments = explode('/', $path);

        if (
            $maximumBytes < 1 || $maximumBytes > 4194304 || $path === ''
            || preg_match('~^[A-Za-z0-9_. -]+(?:/[A-Za-z0-9_. -]+)*$~D', $path) !== 1
            || \in_array('..', $segments, true) || \in_array('.', $segments, true)
        ) {
            throw new \InvalidArgumentException('Invalid bounded storage path.');
        }

        $root = $this->root . '/' . self::PUBLIC_FOLDER;
        $base = realpath($root);
        $resolved = realpath($root . '/' . $path);
        $prefix = $base === false ? '' : str_replace('\\', '/', $base) . '/';
        $file = $resolved === false ? '' : str_replace('\\', '/', $resolved);

        if (PHP_OS_FAMILY === 'Windows') {
            $prefix = strtolower($prefix);
            $file = strtolower($file);
        }

        if ($base === false || $resolved === false || !str_starts_with($file, $prefix) || !is_file($resolved)) {
            throw new \RuntimeException('The public storage file is unavailable or outside its root.');
        }

        // Streamed with a hard cap: loading an arbitrary whole file first would
        // bypass the renderer's memory limit.
        $stream = fopen($resolved, 'rb');

        if ($stream === false) {
            throw new \RuntimeException('Cannot open the public storage file.');
        }

        try {
            $stat = fstat($stream);

            if ($stat === false || $stat['size'] > $maximumBytes) {
                throw new \RuntimeException('The public storage file exceeds its byte limit.');
            }

            $bytes = stream_get_contents($stream, $maximumBytes + 1);

            if ($bytes === false || \strlen($bytes) > $maximumBytes) {
                throw new \RuntimeException('The public storage file exceeds its byte limit.');
            }

            return $bytes;
        } finally {
            fclose($stream);
        }
    }

    public function putPrivate(string $path, string $contents): void
    {
        $this->ensurePrivateRoot();
        $this->write($this->privatePath($path), $contents);
    }

    public function putUpload(string $path, string $contents, bool $public, int $userId): void
    {
        // Authoring uploads are permission-gated separately from learner work.
        if (!preg_match('~^(?:attempts|submissions)/~', $path)) {
            $public ? $this->putPublic($path, $contents) : $this->putPrivate($path, $contents);
            return;
        }
        if ($public) {
            $quotaRoot = LocalStorageSafety::absolute($this->publicRoot());
            if (!is_dir($quotaRoot) && !mkdir($quotaRoot, 0755, true) && !is_dir($quotaRoot)) {
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

    public function publicRoot(): string
    {
        return self::canonical($this->root) . '/' . self::PUBLIC_FOLDER;
    }

    public function readPrivate(string $path): string
    {
        $absolute = $this->privatePath($path);
        $contents = is_file($absolute) ? file_get_contents($absolute) : false;

        if ($contents === false) {
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
                unlink($absolute);
            }
        }
    }

    public function publicUrl(string $path): string
    {
        return rtrim(Uri::root(), '/') . '/' . self::PUBLIC_FOLDER . '/' . $this->relative($path);
    }

    public function signedUrl(string $path, int $expiresInSeconds): string
    {
        $relative = LocalStorageSafety::downloadable($path);
        $expires = time() + max(1, $expiresInSeconds);

        $query = [
            'option' => 'com_vidyalms',
            'task' => self::DOWNLOAD_TASK,
            'path' => $relative,
            'expires' => (string) $expires,
            'signature' => self::sign($relative, $expires),
        ];

        return rtrim(Uri::root(), '/') . '/index.php?' . http_build_query($query);
    }

    /**
     * Validates a signed download request. Returns false for a tampered
     * signature, an expired link or a path that escapes the private root.
     */
    public static function verifySignature(string $path, int $expires, string $signature): bool
    {
        if ($expires < time()) {
            return false;
        }

        try {
            $relative = LocalStorageSafety::downloadable($path);
        } catch (\InvalidArgumentException) {
            return false;
        }

        return hash_equals(self::sign($relative, $expires), $signature);
    }

    public static function sign(string $path, int $expires): string
    {
        return hash_hmac('sha256', self::SIGNATURE_CONTEXT . '|' . $path . '|' . $expires, self::secret());
    }

    public function privateRoot(): string
    {
        $location = str_replace('\\', '/', $this->privateLocation);

        if (self::isAbsolute($location)) {
            return self::canonical($location);
        }

        return self::canonical($this->root) . '/' . trim($location, '/');
    }

    /**
     * Where the web server would look for a private file, relative to the
     * document root, or null when no URL reaches the private root at all.
     *
     * This is what a verification script needs to build the URL it must prove
     * is not served, and what an administrator screen needs to tell the truth
     * about the current arrangement.
     */
    public function webExposedPath(): ?string
    {
        $root         = $this->privateRoot();
        $documentRoot = self::canonical($this->documentRoot ?? $this->root);

        if (!self::isWithin($root, $documentRoot)) {
            return null;
        }

        return trim(substr($root, \strlen($documentRoot)), '/');
    }

    public function isWebExposed(): bool
    {
        return $this->webExposedPath() !== null;
    }

    public function privatePath(string $path): string
    {
        if (self::normalise($path) === '.vidyalms-owner.json') {
            throw new \RuntimeException('Private storage ownership metadata is reserved.');
        }
        return LocalStorageSafety::path($this->safePrivateRoot(), $path);
    }

    /**
     * Creates the private root and its guards, and refuses to hand back a root
     * it cannot write. Public so the install script and the smoke harness can
     * make the same demand the first private write would.
     */
    public function ensurePrivateRoot(): void
    {
        $root = $this->safePrivateRoot();

        if (!is_dir($root) && !mkdir($root, 0700, true) && !is_dir($root)) {
            throw new \RuntimeException(sprintf(
                'Cannot create the private storage folder "%s". Point the VidyaLMS private files path at a '
                . 'writable location outside the web root using VIDYALMS_PRIVATE_DIR.',
                $root
            ));
        }

        if (!is_writable($root)) {
            throw new \RuntimeException(sprintf(
                'The private storage folder "%s" is not writable. Point the VidyaLMS private files path at a '
                . 'writable location outside the web root using VIDYALMS_PRIVATE_DIR.',
                $root
            ));
        }

        $this->assertOwnership($root, true);

        $this->writeGuard($root . '/.htaccess', self::DENY_HTACCESS);
        $this->writeGuard($root . '/web.config', self::DENY_WEBCONFIG);
        $this->writeGuard($root . '/index.html', '');
    }

    private function publicPath(string $path): string
    {
        return LocalStorageSafety::path($this->publicRoot(), $path);
    }

    private function safePrivateRoot(): string
    {
        $root = LocalStorageSafety::privateRoot($this->privateRoot(), [
            $this->root, $this->documentRoot ?? '', (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''),
            defined('JPATH_PUBLIC') ? JPATH_PUBLIC : '',
        ]);
        $this->assertOwnership($root, false);
        return $root;
    }

    /**
     * Bind every private operation to the installation's filesystem root and host.
     * A copied database/configuration alone cannot claim another site's files.
     * Empty roots can be claimed on first write; existing unmarked data needs an
     * explicit server-owner migration. No read or health check claims a root.
     */
    private function assertOwnership(string $root, bool $claim): void
    {
        if (!is_dir($root)) {
            return;
        }
        $marker = LocalStorageSafety::path($root, '.vidyalms-owner.json');
        $exists = file_exists($marker);
        if ($exists && !is_file($marker)) {
            throw new \RuntimeException('Invalid private storage ownership marker.');
        }
        if (!$exists && !$claim) {
            if (!$this->emptyPrivateRoot($root)) {
                throw new \RuntimeException('Unmarked private storage requires verified server-owner migration.');
            }
            return;
        }
        $created = false;
        $handle = $exists ? @fopen($marker, 'rb') : @fopen($marker, 'x+b');
        if (!$exists && $handle !== false) {
            $created = true;
        } elseif ($handle === false) {
            // Another writer may have claimed it. Its shared lock below waits
            // until the complete marker is persisted, then verifies its owner.
            LocalStorageSafety::path($root, '.vidyalms-owner.json');
            $handle = is_file($marker) ? @fopen($marker, 'rb') : false;
        }
        if ($handle === false) {
            throw new \RuntimeException('Cannot open private storage ownership marker.');
        }
        $complete = false;
        try {
            if (!flock($handle, $created ? LOCK_EX : LOCK_SH)) {
                throw new \RuntimeException('Cannot lock private storage ownership marker.');
            }
            $stat = fstat($handle);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
                throw new \RuntimeException('Private storage ownership requires an ordinary, unlinked file.');
            }
            $expected = self::ownershipMarker($this->root);
            if ($created) {
                if (!$this->emptyPrivateRoot($root, true)) {
                    throw new \RuntimeException('Cannot claim nonempty private storage.');
                }
                if (!chmod($marker, 0600) || fwrite($handle, $expected) !== strlen($expected) || !fflush($handle)) {
                    throw new \RuntimeException('Cannot persist private storage ownership marker.');
                }
            } else {
                $actual = stream_get_contents($handle, 4097);
                if (!is_string($actual) || !hash_equals($expected, $actual)) {
                    throw new \RuntimeException('Private storage belongs to another Joomla installation.');
                }
            }
            $complete = true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
            if ($created && !$complete) {
                // Only our exclusively created marker, never existing data.
                @unlink($marker);
            }
        }
    }

    private function emptyPrivateRoot(string $root, bool $allowMarker = false): bool
    {
        foreach (new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$allowMarker || $entry->getFilename() !== '.vidyalms-owner.json') {
                return false;
            }
        }
        return true;
    }

    /** Server-owner migration helper: returns bytes; never claims or changes files. */
    public static function ownershipMarker(string $siteRoot): string
    {
        $siteRoot = LocalStorageSafety::absolute($siteRoot);
        if (DIRECTORY_SEPARATOR === '\\') {
            $siteRoot = strtolower($siteRoot);
        }
        return json_encode(['version' => 1, 'host' => 'joomla',
            'installation' => hash('sha256', $siteRoot . "\0" . php_uname('n'))], JSON_THROW_ON_ERROR) . "\n";
    }

    private function relative(string $path): string
    {
        return self::normalise($path);
    }

    /**
     * Path validation happens before any filesystem call, not after: a ".."
     * segment, a backslash or a null byte is rejected outright rather than
     * resolved.
     */
    private static function normalise(string $path): string
    {
        return LocalStorageSafety::relative($path);
    }

    /**
     * Forward slashes, no trailing slash. Symlinks are left alone: realpath()
     * returns false for a root that does not exist yet, which is exactly when
     * this is called.
     */
    private static function canonical(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', trim($path)), '/');

        return $path === '' ? '/' : $path;
    }

    private static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('#^[A-Za-z]:/#', $path) === 1;
    }

    /**
     * Windows path comparison is case-insensitive; POSIX is not. Comparing the
     * wrong way round would either miss a containment or invent one.
     */
    private static function isWithin(string $child, string $parent): bool
    {
        $child  = self::canonical($child);
        $parent = self::canonical($parent);

        if (self::sameSegment($child, $parent)) {
            return true;
        }

        return self::sameSegment(substr($child, 0, \strlen($parent) + 1), $parent . '/');
    }

    private static function sameSegment(string $left, string $right): bool
    {
        return \DIRECTORY_SEPARATOR === '\\'
            ? strcasecmp($left, $right) === 0
            : $left === $right;
    }

    private function write(string $absolute, string $contents): void
    {
        LocalStorageSafety::write($absolute, $contents);
    }

    private function writeGuard(string $absolute, string $contents): void
    {
        if (!is_file($absolute)) {
            LocalStorageSafety::write($absolute, $contents);
        }
    }

    private static function secret(): string
    {
        $secret = Factory::getContainer()->get('config')->get('secret');

        if (!\is_string($secret) || $secret === '') {
            throw new \RuntimeException('The site secret is not configured; signed URLs cannot be issued.');
        }

        return $secret;
    }
}
