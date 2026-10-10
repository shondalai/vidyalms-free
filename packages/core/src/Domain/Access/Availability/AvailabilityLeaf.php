<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access\Availability;

/**
 * One condition inside the tree. The tree never interprets a leaf's fields:
 * the payload belongs to the provider named by type and travels as its own
 * versioned document.
 *
 * The version sits on the leaf and not on the document. A single
 * document-level version would force every condition provider to migrate
 * together the first time any one of them gained a field, which means an
 * unrelated first-party change can break a third-party condition.
 */
final class AvailabilityLeaf
{
    /**
     * @param array<string, mixed> $payload the unwrapped body, with no version key
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly int $payloadVersion = 1,
        public readonly ?string $preset = null,
    ) {
    }
}
