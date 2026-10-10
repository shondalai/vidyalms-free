<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;
use VidyaLMS\Component\VidyaLMS\Administrator\Port\JoomlaStorage;

/**
 * Serves one private file for a signed, expiring URL.
 *
 * The signature is minted and checked by the storage port implementation
 * (JoomlaStorage::signedUrl and JoomlaStorage::verifySignature), so the secret
 * never leaves it. This controller only carries the request to that helper and
 * streams the bytes it is allowed to stream: the path it serves is the exact
 * path the signature covers, never one derived from it.
 *
 * @since 1.0.0
 */
class FileController extends BaseController
{
    private const COMPONENT = 'com_vidyalms';

    /**
     * The verification helper sits on the implementation, not on the port
     * interface. A storage implementation that does not offer it fails closed:
     * an unverifiable request is a rejected request.
     */
    private const VERIFY_METHOD = 'verifySignature';

    /**
     * Extension to media type for the private files this site hands out.
     *
     * The credential formats are here because a signed download is how a
     * learner takes a credential away: `jsonld` is the free `vidyalms.json`
     * document, `png` carries the baked Open Badges 2.0 assertion, `jwt` is a
     * Pro `openbadges.vc-jwt` artefact and `pdf` a Business PAdES one. A format
     * this build cannot name is not an error: an unlisted extension streams as
     * application/octet-stream, which is a download rather than a failure.
     *
     * `html` and `htm` are deliberately absent, and that is a security
     * decision rather than an oversight. The free certificate artefact is an
     * HTML document assembled from a site-authored template, and serving it as
     * text/html from this origin would run any script it carries with the
     * visitor's session. It streams as an attachment of unknown type instead;
     * the readable, accessible artefact is the verification page, which the
     * design says in as many words.
     */
    private const CONTENT_TYPES = [
        'pdf'    => 'application/pdf',
        'jsonld' => 'application/ld+json',
        'jwt'    => 'application/vc+jwt',
        'zip'    => 'application/zip',
        'txt'    => 'text/plain; charset=utf-8',
        'csv'    => 'text/csv; charset=utf-8',
        'json'   => 'application/json',
        'xml'    => 'application/xml',
        'png'    => 'image/png',
        'jpg'    => 'image/jpeg',
        'jpeg'   => 'image/jpeg',
        'gif'    => 'image/gif',
        'webp'   => 'image/webp',
        'svg'    => 'image/svg+xml',
        'mp3'    => 'audio/mpeg',
        'mp4'    => 'video/mp4',
        'webm'   => 'video/webm',
        'doc'    => 'application/msword',
        'docx'   => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'    => 'application/vnd.ms-excel',
        'xlsx'   => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt'    => 'application/vnd.ms-powerpoint',
        'pptx'   => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    /**
     * @return void
     */
    public function download()
    {
        $path      = (string) $this->input->get('path', '', 'raw');
        $expires   = (int) $this->input->get('expires', 0, 'int');
        $signature = (string) $this->input->get('signature', '', 'raw');

        $storage = $this->storage();

        if (
            !$this->isSafePath($path)
            || $signature === ''
            || $expires <= 0
            || $expires < time()
            || !$this->hasValidSignature($storage, $path, $expires, $signature)
        ) {
            throw new \Exception(Text::_('COM_VIDYALMS_FILE_FORBIDDEN'), 403);
        }

        // A file the storage no longer holds, and a path that exists only as a
        // public asset, are both "gone" as far as a private download goes.
        try {
            if (!$storage instanceof JoomlaStorage) {
                throw new \RuntimeException('Streaming requires Joomla local storage.');
            }
            $absolute = $storage->privatePath($path);
            $handle = @fopen($absolute, 'rb');
            if ($handle === false) {
                throw new \RuntimeException('Private file is unavailable.');
            }
            $stat = fstat($handle);
            if ($stat === false || ($stat['mode'] & 0170000) !== 0100000 || $stat['nlink'] !== 1) {
                fclose($handle);
                throw new \RuntimeException('Private download requires an ordinary file.');
            }
        } catch (\RuntimeException) {
            throw new \Exception(Text::_('COM_VIDYALMS_FILE_NOT_FOUND'), 404);
        }

        try {
            $this->stream($path, $handle, $stat['size']);
        } finally {
            fclose($handle);
        }
        $this->app->close();
    }

    private function storage(): StoragePort
    {
        $container = Factory::getContainer();

        // The component's provider registers the port; booting covers the case
        // where this request reached the controller before that ran.
        if (!$container->has(StoragePort::class)) {
            $this->app->bootComponent(self::COMPONENT);
        }

        $storage = $container->get(StoragePort::class);

        if (!$storage instanceof StoragePort) {
            throw new \RuntimeException('The VidyaLMS storage port is not registered on the Joomla container.');
        }

        return $storage;
    }

    private function hasValidSignature(StoragePort $storage, string $path, int $expires, string $signature): bool
    {
        if (!method_exists($storage, self::VERIFY_METHOD)) {
            return false;
        }

        return (bool) $storage->{self::VERIFY_METHOD}($path, $expires, $signature);
    }

    /**
     * Logical storage paths are relative, forward-slash and free of traversal.
     * The signature already covers the path; this keeps a mis-signed path from
     * reaching the filesystem at all.
     */
    private function isSafePath(string $path): bool
    {
        if ($path === '' || \strlen($path) > 1024) {
            return false;
        }

        if (str_contains($path, '\\') || str_starts_with($path, '/')) {
            return false;
        }

        if (preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    /** @param resource $handle */
    private function stream(string $path, $handle, int $size): void
    {
        $name      = basename($path);
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        // The host's own list first, because it carries the media and office
        // types a course uploads and the core knows nothing about; then the
        // product's own credential table, so a format that registers is
        // served here and on the WordPress mount the day it does rather than
        // when two hand-maintained lists are next reconciled. html and htm
        // are in neither, which is the security decision stated above.
        $type = self::CONTENT_TYPES[$extension]
            ?? ($extension === 'html' || $extension === 'htm'
                ? 'application/octet-stream'
                : RenderedArtefact::mediaTypeFor($extension) ?? 'application/octet-stream');
        $fallback  = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?? 'download';

        // Joomla/template/compression buffers must not accumulate the download
        // or prefix it with HTML. Refuse a buffer that cannot be removed.
        while (ob_get_level() > 0) {
            if (!@ob_end_clean()) {
                throw new \RuntimeException('Cannot clear the download output buffer.');
            }
        }
        $this->app->allowCache(false);
        $this->app->setHeader('Content-Type', $type, true);
        $this->app->setHeader('Content-Length', (string) $size, true);
        $this->app->setHeader(
            'Content-Disposition',
            'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($name),
            true,
        );
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);
        $this->app->sendHeaders();

        while (!feof($handle) && !connection_aborted()) {
            $chunk = fread($handle, 65536);
            if ($chunk === false) {
                throw new \RuntimeException('Private download could not be completed.');
            }
            echo $chunk;
            flush();
        }
    }
}
