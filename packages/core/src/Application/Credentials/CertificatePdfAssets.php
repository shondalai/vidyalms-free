<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Storage\ReadsPublicFiles;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;

/** Bounded raster copies are part of the published/issued snapshot, not mutable URLs. */
final class CertificatePdfAssets
{
    public const MAXIMUM_ASSET_BYTES = 1048576;
    public const MAXIMUM_TOTAL_BYTES = 4194304;
    public const IMAGE_ELEMENTS = ['image', 'background_image', 'signature_image'];

    public function __construct(private readonly ?StoragePort $storage = null)
    {
    }

    /**
     * @param list<CertificateTemplatePage> $pages
     * @return array<string, array<string, string>>
     */
    public function freeze(array $pages): array
    {
        $assets = [];
        $bytes = 0;
        $pixels = 0;
        $paths = self::paths($pages);

        if (\count($paths) > 32) {
            throw ValidationException::forField('assets', 'A PDF certificate supports at most 32 unique images.');
        }

        foreach ($paths as $path) {
            if (!$this->storage instanceof ReadsPublicFiles || !self::safePath($path)) {
                throw ValidationException::forField('assets', 'A PDF image must be a readable local storage asset.');
            }

            try {
                $contents = $this->storage->readPublic($path, self::MAXIMUM_ASSET_BYTES);
            } catch (\RuntimeException | \InvalidArgumentException) {
                throw ValidationException::forField('assets', 'A PDF image is missing, unsafe or too large.');
            }

            $type = self::imageType($contents);
            $bytes += \strlen($contents);
            $dimensions = getimagesizefromstring($contents);
            if ($dimensions === false) {
                throw ValidationException::forField('assets', 'A certificate image has invalid dimensions.');
            }
            $pixels += $dimensions[0] * $dimensions[1];

            if ($bytes > self::MAXIMUM_TOTAL_BYTES || $pixels > 8388608) {
                throw ValidationException::forField('assets', 'Certificate images exceed the 4 MiB or 8 megapixel total limit.');
            }

            $assets[$path] = [
                'media_type' => $type,
                'sha256' => hash('sha256', $contents),
                'base64' => base64_encode($contents),
            ];
        }

        return $assets;
    }

    /**
     * @param list<CertificateTemplatePage> $pages
     * @return list<string>
     */
    public static function paths(array $pages): array
    {
        $paths = [];

        foreach ($pages as $page) {
            if ($page->background !== null && $page->background !== '') {
                $paths[] = $page->background;
            }

            foreach ($page->elements as $element) {
                if (\in_array($element->element, self::IMAGE_ELEMENTS, true)) {
                    $path = $element->data['path'] ?? null;

                    if (\is_string($path) && $path !== '') {
                        $paths[] = $path;
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    public static function safePath(string $path): bool
    {
        return $path !== '' && \strlen($path) <= 500
            && preg_match('~^[A-Za-z0-9_. -]+(?:/[A-Za-z0-9_. -]+)*$~D', $path) === 1
            && !ctype_digit($path)
            && !\in_array('..', explode('/', $path), true)
            && !\in_array('.', explode('/', $path), true);
    }

    public static function imageType(string $bytes): string
    {
        if ($bytes === '' || \strlen($bytes) > self::MAXIMUM_ASSET_BYTES) {
            throw ValidationException::forField('assets', 'A certificate image must not exceed 1 MiB.');
        }

        $size = @getimagesizefromstring($bytes);
        $type = $size === false ? null : $size['mime'];

        if (
            $size === false || !\in_array($type, ['image/png', 'image/jpeg'], true)
            || $size[0] < 1 || $size[1] < 1 || $size[0] > 4096 || $size[1] > 4096 || $size[0] * $size[1] > 8388608
        ) {
            throw ValidationException::forField('assets', 'Use PNG or JPEG, at most 4096 px per side and 8 megapixels.');
        }

        return $type;
    }
}
