<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Upload;

use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;

/**
 * One uploaded file in, one stored file out, with every check in between and
 * no host in it.
 *
 * This is the whole of what an upload endpoint decides; the controllers around
 * it do transport (the session token, the multipart parsing, proving the
 * temporary file really was uploaded, and the emit) and nothing else. The
 * order matters and is the order a defence has to be read in:
 *
 *   1. the purpose exists in the policy
 *   2. the bytes are above zero and under the smaller of the purpose's ceiling
 *      and the host's own
 *   3. the extension is one the purpose accepts
 *   4. the bytes sniff, through fileinfo, as a media type that extension is
 *      allowed to carry: the client's file name and its Content-Type are both
 *      ignored, and the two independent facts have to agree
 *   5. an image purpose additionally decodes the bytes, so a document that
 *      merely claims image/png is refused a second time
 *   6. the stored path is generated, never taken: a random token, a validated
 *      extension and a reduced form of the author's name for a recognisable
 *      download. "course.php.png" cannot become a PHP file and two authors
 *      uploading "notes.pdf" cannot overwrite each other.
 *
 * The bytes arrive already read, and the ceiling arrives already worked out,
 * because both of those are the host's business: proving that a temporary
 * file is a real upload and knowing what the server's own limits are cannot
 * be answered the same way on every platform. Everything after that can, and
 * this is the one copy of it.
 *
 * The prose in every refusal is deliberately untranslated and is the same
 * string on every host. The parity suite compares the two hosts' answers byte
 * for byte, which is what keeps one platform from quietly accepting what the
 * other refuses.
 */
final class UploadIntake
{
    /**
     * The reduced form of the author's file name that goes into the path,
     * after the random token. Long enough to recognise a download by, short
     * enough that a path stays inside the 1024 character storage column.
     */
    private const NAME_HINT_LENGTH = 40;

    private const DISPLAY_NAME_LENGTH = 120;

    public function __construct(
        private readonly UploadPolicy $policy,
        private readonly StoragePort $storage,
        private readonly RandomPort $random,
    ) {
    }

    /**
     * @param string $contents      the uploaded bytes, read by the host after
     *                              it proved they came from an upload
     * @param string $clientName    the file name the client sent, untrusted
     * @param int    $hostCeiling   the largest upload the host itself accepts,
     *                              or zero when it states none
     *
     * @throws ApiProblem when the upload is not one this site accepts
     */
    public function store(
        string $contents,
        string $clientName,
        string $purposeKey,
        int $userId,
        int $hostCeiling = 0,
    ): StoredUpload {
        $purpose = $this->policy->purpose($purposeKey);

        if ($purpose === null) {
            throw ApiProblem::validation(['purpose' => sprintf(
                'Unknown upload purpose "%s". This site accepts: %s.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $purposeKey,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', $this->policy->keys()),
            )]);
        }

        ['extension' => $extension, 'mime' => $mime, 'size' => $size] = self::inspect($purpose, $contents, $clientName, $hostCeiling);

        $path = $this->pathFor($purpose, $extension, $userId, $clientName);

        if ($this->storage instanceof UploadStorage) {
            $this->storage->putUpload($path, $contents, $purpose->isPublic(), $userId);
        } elseif ($purpose->isPublic()) {
            $this->storage->putPublic($path, $contents);
        } else {
            $this->storage->putPrivate($path, $contents);
        }

        return new StoredUpload(
            $path,
            self::displayName($clientName, $extension),
            $size,
            $mime,
            $purpose->isPublic() ? $this->storage->publicUrl($path) : null,
        );
    }

    /** @return array{extension: string, mime: string, size: int} */
    public static function inspect(UploadPurpose $purpose, string $contents, string $clientName, int $hostCeiling = 0): array
    {
        $ceiling = $hostCeiling > 0 ? min($purpose->maxBytes, $hostCeiling) : $purpose->maxBytes;

        if ($contents === '') {
            throw ApiProblem::validation(['file' => 'The uploaded file is empty.']);
        }

        // The real size, not a reported one: the two agree on every honest
        // upload and the stored answer must describe the bytes on disk.
        $size = \strlen($contents);

        if ($size > $ceiling) {
            throw new ApiProblem(413, 'Payload too large', sprintf(
                'The file is %s and the limit is %s.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::formatBytes($size),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::formatBytes($ceiling),
            ));
        }

        $extension = self::extensionOf($clientName);

        if ($extension === '') {
            throw ApiProblem::validation(['file' => sprintf(
                'A "%s" upload needs a file name carrying one of these extensions: %s.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $purpose->key,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', $purpose->extensions()),
            )]);
        }

        if (!$purpose->accepts($extension)) {
            throw ApiProblem::validation(['file' => sprintf(
                'A "%s" upload does not accept ".%s" files. It accepts: %s.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $purpose->key,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $extension,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', $purpose->extensions()),
            )]);
        }

        $mime = self::sniff($contents);

        if (!$purpose->acceptsSniffedType($extension, $mime)) {
            throw ApiProblem::validation(['file' => sprintf(
                'The contents of this file are "%s", which a ".%s" upload does not carry.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $mime,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $extension,
            )]);
        }

        if ($purpose->raster) {
            self::requireRaster($contents, $purpose, $extension);
        }

        return ['extension' => $extension, 'mime' => $mime, 'size' => $size];
    }

    /**
     * The bytes have to decode as an image, and as the same image the sniff
     * reported. getimagesize refuses SVG and HTML outright, which is the
     * property the image purpose depends on.
     */
    private static function requireRaster(string $contents, UploadPurpose $purpose, string $extension): void
    {
        $info = @getimagesizefromstring($contents);
        $type = \is_array($info) ? strtolower((string) $info['mime']) : '';

        if ($type === '' || !$purpose->acceptsSniffedType($extension, $type)) {
            throw ApiProblem::validation(['file' => 'This file is not a raster image.']);
        }
    }

    /**
     * A generated path, never the client's own. The random token makes it
     * unique whatever two authors call their files; the name hint keeps a
     * download recognisable; the extension is the validated one, so a name
     * that carried a second extension cannot bring it along.
     */
    private function pathFor(UploadPurpose $purpose, string $extension, int $userId, string $clientName): string
    {
        $token = substr(str_replace('-', '', $this->random->uuid()), 0, 32);

        if ($token === '') {
            throw ApiProblem::internal();
        }

        $hint = self::nameHint($clientName);

        return sprintf(
            '%s/%d/%s/%s%s.%s',
            $purpose->pathPrefix,
            max(0, $userId),
            gmdate('Y-m'),
            $token,
            $hint === '' ? '' : '-' . $hint,
            $extension,
        );
    }

    /**
     * fileinfo is not optional. Guessing from the extension is exactly what
     * this endpoint exists not to do, so a PHP without the extension refuses
     * uploads rather than accepting them unchecked.
     */
    private static function sniff(string $contents): string
    {
        if (!class_exists(\finfo::class)) {
            throw new ApiProblem(
                500,
                'Internal error',
                'The PHP fileinfo extension is required before this site can accept uploads.',
            );
        }

        try {
            $finfo = new \finfo(\FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($contents);
        } catch (\Throwable) {
            $mime = false;
        }

        return \is_string($mime) && $mime !== '' ? strtolower($mime) : 'application/octet-stream';
    }

    /**
     * The lower-cased extension of a client file name, without the dot.
     */
    private static function extensionOf(string $fileName): string
    {
        $extension = pathinfo(self::baseName($fileName), \PATHINFO_EXTENSION);

        return strtolower(\is_string($extension) ? $extension : '');
    }

    /**
     * What the picker shows and what a resource lesson records as the file's
     * name. It keeps the author's words, unicode included, and loses only what
     * a file name may not carry: control characters, path separators and the
     * shell-hostile punctuation. The extension is the validated one.
     */
    public static function displayName(string $fileName, string $extension): string
    {
        $base = pathinfo(self::baseName($fileName), \PATHINFO_FILENAME);
        $base = \is_string($base) ? $base : '';
        $base = (string) preg_replace('/[\x00-\x1F\x7F<>:"\/\\\\|?*]+/u', ' ', $base);
        $base = trim((string) preg_replace('/\s+/u', ' ', $base));

        if ($base === '') {
            $base = 'file';
        }

        if (mb_strlen($base) > self::DISPLAY_NAME_LENGTH) {
            $base = rtrim(mb_substr($base, 0, self::DISPLAY_NAME_LENGTH));
        }

        return $base . '.' . $extension;
    }

    /**
     * The part of the client's name that is safe as one path segment. Reduced
     * to ASCII word characters, so it can never be the whole story of a path.
     */
    private static function nameHint(string $fileName): string
    {
        $base = pathinfo(self::baseName($fileName), \PATHINFO_FILENAME);
        $base = (string) preg_replace('/[^A-Za-z0-9_-]+/', '-', \is_string($base) ? $base : '');
        $base = trim($base, '-_.');

        return $base === '' ? '' : strtolower(substr($base, 0, self::NAME_HINT_LENGTH));
    }

    /**
     * basename() alone is not enough: a Windows client sends a backslash
     * separated name and basename() on Linux keeps the whole thing.
     */
    private static function baseName(string $fileName): string
    {
        $name = str_replace('\\', '/', trim($fileName));
        $cut = strrpos($name, '/');

        return $cut === false ? $name : substr($name, $cut + 1);
    }

    /**
     * How a byte count is said out loud in a refusal. Shared so the two hosts
     * word the same limit the same way, which the parity suite compares.
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return round($bytes / (1024 * 1024), 1) . ' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }

        return $bytes . ' B';
    }
}
