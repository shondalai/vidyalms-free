<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Upload;

/**
 * One kind of upload the host accepts: what it may be called, what its bytes
 * must actually be, how large it may get, and where it is stored.
 *
 * A purpose is a value, not a screen. The workbench's file picker names one
 * ("resource", "image") and everything the endpoint then enforces is read off
 * this object, so a new kind of upload is a new purpose registered into
 * UploadPolicy rather than another branch in the endpoint.
 *
 * ## Two independent checks, both required
 *
 * `types` maps an accepted extension to the media types its bytes are allowed
 * to sniff as. The extension is validated against the map's keys and the
 * sniffed type against that key's list, so neither the client's file name nor
 * its Content-Type is ever taken on trust and the two have to agree: a ".pdf"
 * carrying a zip is refused, and so is a genuine PNG uploaded as ".pdf".
 *
 * The lists are deliberately generous about the same format under different
 * libmagic versions (an OOXML document is a zip to an older one) and
 * deliberately narrow about anything else. An extension whose bytes cannot be
 * pinned down to a stated type does not belong in a purpose at all.
 */
final class UploadPurpose
{
    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_PUBLIC = 'public';

    /**
     * @param string                      $key        the name the client sends
     * @param array<string, list<string>> $types      extension => the media types its bytes may sniff as
     * @param int                         $maxBytes   the ceiling this purpose sets, before PHP's own
     * @param string                      $visibility one of the two VISIBILITY constants
     * @param string                      $pathPrefix the first segment of every path stored under it
     * @param bool                        $raster     whether the bytes must decode as a raster image
     */
    public function __construct(
        public readonly string $key,
        public readonly array $types,
        public readonly int $maxBytes,
        public readonly string $visibility = self::VISIBILITY_PRIVATE,
        public readonly string $pathPrefix = 'uploads',
        public readonly bool $raster = false,
    ) {
        if ($key === '' || preg_match('/^[a-z][a-z0-9_-]*$/', $key) !== 1) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unsupported upload purpose key "%s".', $key));
        }

        if ($types === []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('The upload purpose "%s" accepts nothing.', $key));
        }

        if ($maxBytes <= 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('The upload purpose "%s" has no size ceiling.', $key));
        }

        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $pathPrefix) !== 1) {
            throw new \InvalidArgumentException('An upload purpose needs one safe storage prefix.');
        }

        if (!\in_array($visibility, [self::VISIBILITY_PRIVATE, self::VISIBILITY_PUBLIC], true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unsupported visibility "%s".', $visibility));
        }
    }

    public function isPublic(): bool
    {
        return $this->visibility === self::VISIBILITY_PUBLIC;
    }

    /**
     * The extensions this purpose accepts, lower case, in declaration order.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return array_keys($this->types);
    }

    public function accepts(string $extension): bool
    {
        return isset($this->types[strtolower($extension)]);
    }

    /**
     * Whether bytes that sniffed as $mime are allowed to be stored under
     * $extension. Both arguments have already been validated on their own; this
     * is the agreement between them.
     */
    public function acceptsSniffedType(string $extension, string $mime): bool
    {
        $allowed = $this->types[strtolower($extension)] ?? [];

        return \in_array(strtolower($mime), $allowed, true);
    }
}
