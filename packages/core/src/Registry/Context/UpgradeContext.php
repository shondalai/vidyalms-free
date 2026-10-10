<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Context;

/**
 * What a definition upgrader may read: the type key it is upgrading, the
 * version the stored row was written at, and whether this row is nested
 * inside a composite (a nested definition has no questions row and
 * therefore no definition_version column of its own).
 *
 * A context for the same reason every other provider method takes one:
 * these providers do not exist yet, so the parameter is in the signature
 * from the start rather than being added later and breaking everybody.
 */
final class UpgradeContext
{
    private function __construct(
        public readonly string $typeKey,
        public readonly int $fromVersion,
        public readonly ?string $nestedPath,
    ) {
    }

    public static function create(string $typeKey, int $fromVersion, ?string $nestedPath = null): self
    {
        return new self($typeKey, $fromVersion, $nestedPath);
    }

    public function isNested(): bool
    {
        return $this->nestedPath !== null;
    }
}
