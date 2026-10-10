<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http;

/**
 * The neutral response a handler returns. Adapters serialise it onto their
 * host's HTTP stack.
 *
 * A response carrying bytes rather than a document says so on the object.
 * The convention used to be an inline literal, `['content' => base64,
 * 'encoding' => 'base64']`, specified by a comment: each adapter then wrote
 * its own heuristic to recognise it, the two had to agree forever on a rule
 * written down nowhere, and a third adapter would have invented a third. A
 * drift there does not fail a test, it serves base64 text where a wallet
 * expected a PNG. So the core decides what a binary response is, and an
 * emitter asks rather than sniffs.
 */
final class ApiResponse
{
    /**
     * The wire members of the base64 envelope, kept as the payload shape so
     * an emitter written against the old convention keeps working while it
     * migrates to isBinary().
     */
    public const BINARY_CONTENT_KEY = 'content';
    public const BINARY_ENCODING_KEY = 'encoding';
    public const BINARY_ENCODING = 'base64';

    /**
     * @param array<string, mixed>|null $data
     * @param array<string, string>     $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $data,
        public readonly array $headers = [],
        /**
         * The bytes themselves when this response is binary, held beside the
         * wire envelope rather than inside it: an ordinary JSON document that
         * happens to carry a "content" field must never be mistaken for
         * bytes, and that is exactly the judgement each adapter was making
         * for itself.
         */
        private readonly ?string $bytes = null,
    ) {
    }

    /**
     * A response whose payload is bytes under a declared media type.
     *
     * @param array<string, string> $headers merged over the ones this factory sets
     */
    public static function binary(
        string $bytes,
        string $mediaType,
        string $filename,
        array $headers = [],
        string $disposition = 'inline',
    ): self {
        return new self(
            200,
            [
                self::BINARY_CONTENT_KEY => base64_encode($bytes),
                self::BINARY_ENCODING_KEY => self::BINARY_ENCODING,
            ],
            array_merge([
                'Content-Type' => $mediaType,
                'Content-Disposition' => $disposition . '; filename="' . str_replace('"', '', $filename) . '"',
            ], $headers),
            $bytes,
        );
    }

    public function isBinary(): bool
    {
        return $this->bytes !== null;
    }

    /**
     * The raw bytes, for an emitter that writes them straight out. Empty for
     * a response that is not binary, which isBinary() answers first.
     */
    public function binaryBytes(): string
    {
        return $this->bytes ?? '';
    }

    /**
     * The same response with these headers merged over its own.
     *
     * Middleware that adds a header must use this rather than rebuilding the
     * object: the three-argument constructor drops the bytes, so a binary
     * credential left NoIndexMiddleware as an ordinary document and every
     * adapter's emitter, which asks rather than sniffs, wrote base64 text
     * under an image/png Content-Type. The old convention hid that, because
     * the envelope it sniffed for rides on `data` and survived the rebuild.
     *
     * The passed headers win, which is the precedence the one caller already
     * had: a policy header a middleware sets is the site's answer, not the
     * handler's suggestion.
     *
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $this->data, $headers + $this->headers, $this->bytes);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function ok(array $data): self
    {
        return new self(200, $data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function created(array $data): self
    {
        return new self(201, $data);
    }

    public static function noContent(): self
    {
        return new self(204, null);
    }
}
