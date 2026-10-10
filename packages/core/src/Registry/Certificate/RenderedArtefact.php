<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

/**
 * Bytes plus what they are.
 *
 * A render returns this rather than a string because render(): string cannot
 * express a PDF and abusing a return type is how a contract rots. The free
 * build produces text/html through it, which is what gives the shape a call
 * site before any binary renderer exists: a capability with no call site is a
 * capability that will be wrong the first time it is used.
 */
final class RenderedArtefact
{
    /**
     * The one media-type table this product owns, in the package that owns
     * the artefact shape.
     *
     * It is published rather than private because four closed copies of parts
     * of it existed and two of them already disagreed: the same baked SVG
     * credential downloaded as image/svg+xml on one host and as an unknown
     * blob on the other. A new format registers and both mounts serve it.
     *
     * @var array<string, string>
     */
    public const EXTENSIONS = [
        'text/html' => 'html',
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
        'application/ld+json' => 'json',
        'application/json' => 'json',
        'application/vc+jwt' => 'jwt',
    ];

    /**
     * The extension a media type is served under when the table has no entry
     * for it. It is deliberately not a guess at the type.
     */
    public const DEFAULT_EXTENSION = 'bin';

    /**
     * Which media type an extension answers, where the table maps two types
     * to one extension. A credential stored as .json is served as plain JSON,
     * because that is the type every client can read; the linked-data type is
     * chosen by content negotiation and never by a filename.
     *
     * @var array<string, string>
     */
    private const CANONICAL_TYPES = ['json' => 'application/json'];

    private function __construct(
        public readonly string $bytes,
        public readonly string $mediaType,
        public readonly string $suggestedFilename,
    ) {
    }

    public static function of(string $bytes, string $mediaType, string $suggestedFilename): self
    {
        return new self($bytes, $mediaType, $suggestedFilename);
    }

    public static function html(string $document, string $suggestedFilename): self
    {
        return new self($document, 'text/html', $suggestedFilename);
    }

    /**
     * The extension the storage path takes, derived from the media type
     * rather than assumed, because the task that stores this used to write
     * every artefact to a .html path whatever it held.
     */
    public function extension(): string
    {
        return self::extensionFor($this->mediaType);
    }

    public static function extensionFor(string $mediaType): string
    {
        return self::EXTENSIONS[strtolower(trim($mediaType))] ?? self::DEFAULT_EXTENSION;
    }

    /**
     * The inverse, for a host that has a filename and needs a Content-Type.
     * Null means this product does not claim the extension, which is the
     * point at which a host may fall back to its own list.
     */
    public static function mediaTypeFor(string $extension): ?string
    {
        $extension = strtolower(ltrim(trim($extension), '.'));

        if (isset(self::CANONICAL_TYPES[$extension])) {
            return self::CANONICAL_TYPES[$extension];
        }

        foreach (self::EXTENSIONS as $mediaType => $candidate) {
            if ($candidate === $extension) {
                return $mediaType;
            }
        }

        return null;
    }

    /**
     * Whether bytes of this media type are text a utf8mb4 column can hold.
     *
     * It is asked rather than assumed because credential_json is a MEDIUMTEXT
     * column in a utf8mb4 table: raw PDF or PNG bytes are not valid utf8mb4
     * and MySQL in strict mode refuses them, so a site that configured a
     * signing key for a binary format and none for a textual one would have
     * had every issuance roll back for ever.
     */
    public static function isTextual(string $mediaType): bool
    {
        $mediaType = strtolower(trim($mediaType));

        return str_starts_with($mediaType, 'text/')
            || $mediaType === 'application/json'
            || str_ends_with($mediaType, '+json')
            || str_ends_with($mediaType, '+xml')
            || str_ends_with($mediaType, '+jwt');
    }

    public function checksum(): string
    {
        return hash('sha256', $this->bytes);
    }
}
