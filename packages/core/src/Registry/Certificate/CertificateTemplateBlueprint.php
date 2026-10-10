<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Certificate;

/**
 * Provider-owned editable design data, not a stored or published template.
 *
 * Source contains no database identities, ownership, default flag or live
 * publication state. A future authorized clone boundary must allocate those
 * independently and retain key/version/sourceDigest as provenance. Locale
 * and translated copy are part of the source digest: a translation is a
 * different materialization, not a later mutation of a holder's document.
 *
 * @phpstan-type ElementSource array{
 *   element: string, name: string, data: array<string, mixed>, data_version: int,
 *   sequence: int, posx: int, posy: int, width: int, height: int, refpoint: string, alignment: string
 * }
 * @phpstan-type PageSource array{
 *   sequence: int, width: int, height: int, orientation: string,
 *   margin_top: int, margin_right: int, margin_bottom: int, margin_left: int,
 *   background: null, elements: list<ElementSource>
 * }
 * @phpstan-type TemplateSource array{
 *   name: string, format: string, renderer: string, achievement_type: string,
 *   locale: string, body: string, expiry_mode: string, expiry_days: null,
 *   expiry_at: null, pages: list<PageSource>
 * }
 */
final class CertificateTemplateBlueprint
{
    public readonly string $sourceDigest;

    /**
     * @param list<string>   $requiredElements exact registered free provider keys
     * @param list<string>   $requiredTokens exact dotted token names without braces
     * @param TemplateSource $source complete editable source, dimensions in millimetres
     */
    public function __construct(
        public readonly string $key,
        public readonly int $version,
        public readonly int $schemaVersion,
        public readonly string $nameKey,
        public readonly string $descriptionKey,
        public readonly string $categoryKey,
        public readonly string $paperPreset,
        public readonly array $requiredElements,
        public readonly array $requiredTokens,
        public readonly array $source,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/D', $key) !== 1) {
            throw new \InvalidArgumentException('A certificate blueprint needs a stable dotted key.');
        }
        if ($version < 1 || $schemaVersion < 1) {
            throw new \InvalidArgumentException('Certificate blueprint and source schema versions must be positive.');
        }

        $this->sourceDigest = hash('sha256', json_encode($source, \JSON_THROW_ON_ERROR));
    }
}
