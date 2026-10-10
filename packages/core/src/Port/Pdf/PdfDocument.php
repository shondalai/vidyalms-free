<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Pdf;

/** A closed primitive grammar; dimensions are millimetres, font sizes points. */
final class PdfDocument
{
    /**
     * @param list<list<array<string, mixed>>> $pages
     * @param array<string, array<string, string>> $assets Content-addressed PNG/JPEG bytes, never paths.
     * @param list<array{width: float, height: float}> $pageSizes Per-page geometry; absent uses the default dimensions.
     */
    public function __construct(
        public readonly float $width,
        public readonly float $height,
        public readonly array $pages,
        public readonly array $assets,
        public readonly string $title,
        public readonly string $createdAt,
        public readonly bool $preview,
        public readonly array $pageSizes = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
