<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

/**
 * The shared passage form, read off a request body. Create and replace take
 * the same shape, so they read it the same way.
 */
final class StimulusFields
{
    /** @var array<string, mixed>|null */
    public readonly ?array $media;

    /**
     * @param array<string, mixed>|null $media
     */
    private function __construct(
        public readonly string $title,
        public readonly ?string $body,
        ?array $media,
        public readonly ?string $altText,
        public readonly ?string $longDescription,
    ) {
        $this->media = $media;
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $media = \array_key_exists('media', $body) && \is_array($body['media'])
            ? Body::object($body, 'media')
            : null;

        return new self(
            Body::requiredString($body, 'title'),
            Body::nullableString($body, 'body'),
            $media,
            Body::nullableString($body, 'alt_text'),
            Body::nullableString($body, 'long_description'),
        );
    }
}
