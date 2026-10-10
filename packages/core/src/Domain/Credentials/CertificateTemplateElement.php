<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * One drawn thing, as the host stores it.
 *
 * The split is the load-bearing one: sequence, position, size, reference
 * point and alignment are columns because the designer and the layout service
 * own them, while everything element-specific lives in the versioned data
 * payload because it belongs to the element provider. mod_customcert shipped
 * font, fontsize, colour and width as dedicated columns, found they were a
 * ceiling, and moved them into the payload in its 5.2.0 release. The finding
 * is free and there is no reason to buy it twice.
 */
final class CertificateTemplateElement
{
    public const REFPOINT_TOP_LEFT = 'top_left';

    /**
     * The spelling the first element rows were written under. It is still
     * read, and it is not offered for new writes: the contract's vocabulary
     * is British, and one stored value that lays out and another that does
     * not is the silent misplacement this vocabulary exists to prevent.
     */
    public const REFPOINT_TOP_CENTER = 'top_center';
    public const REFPOINT_TOP_CENTRE = 'top_centre';
    public const REFPOINT_TOP_RIGHT = 'top_right';
    public const REFPOINT_CENTRE = 'centre';
    public const REFPOINT_BOTTOM_LEFT = 'bottom_left';
    public const REFPOINT_BOTTOM_CENTRE = 'bottom_centre';
    public const REFPOINT_BOTTOM_RIGHT = 'bottom_right';

    /**
     * @param array<string, mixed> $data the unwrapped payload; StoredShape owns the envelope
     */
    public function __construct(
        public readonly int $id,
        public readonly int $pageId,
        public readonly string $element,
        public readonly array $data = [],
        public readonly int $dataVersion = 1,
        public readonly int $sequence = 1,
        public readonly ?string $name = null,
        public readonly int $posX = 0,
        public readonly int $posY = 0,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
        public readonly string $refPoint = self::REFPOINT_TOP_LEFT,
        public readonly string $alignment = 'left',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'id' => $this->id,
            'element' => $this->element,
            'name' => $this->name,
            'data' => $this->data,
            'data_version' => $this->dataVersion,
            'sequence' => $this->sequence,
            'posx' => $this->posX,
            'posy' => $this->posY,
            'width' => $this->width,
            'height' => $this->height,
            'refpoint' => $this->refPoint,
            'alignment' => $this->alignment,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public static function fromSnapshot(array $snapshot): self
    {
        $data = $snapshot['data'] ?? [];

        return new self(
            (int) ($snapshot['id'] ?? 0),
            (int) ($snapshot['page_id'] ?? 0),
            \is_string($snapshot['element'] ?? null) ? $snapshot['element'] : '',
            \is_array($data) ? $data : [],
            (int) ($snapshot['data_version'] ?? 1),
            (int) ($snapshot['sequence'] ?? 1),
            \is_string($snapshot['name'] ?? null) ? $snapshot['name'] : null,
            (int) ($snapshot['posx'] ?? 0),
            (int) ($snapshot['posy'] ?? 0),
            isset($snapshot['width']) ? (int) $snapshot['width'] : null,
            isset($snapshot['height']) ? (int) $snapshot['height'] : null,
            \is_string($snapshot['refpoint'] ?? null) ? $snapshot['refpoint'] : self::REFPOINT_TOP_LEFT,
            \is_string($snapshot['alignment'] ?? null) ? $snapshot['alignment'] : 'left',
        );
    }
}
