<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access\Availability;

/**
 * One stored restriction row: the subject it gates, the surface it belongs
 * to, the tree, and the item-level visibility fallback.
 */
final class AvailabilityRule
{
    public const VISIBILITY_SHOWN = 'shown';
    public const VISIBILITY_HIDDEN = 'hidden';

    public function __construct(
        public readonly ?int $id,
        public readonly GateSubject $subject,
        public readonly AvailabilitySurface $surface,
        public readonly AvailabilityTree $tree,
        public readonly string $defaultVisibility = self::VISIBILITY_SHOWN,
        public readonly ?int $updatedBy = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function hidesByDefault(): bool
    {
        return $this->defaultVisibility === self::VISIBILITY_HIDDEN;
    }

    /**
     * @return list<string>
     */
    public static function visibilities(): array
    {
        return [self::VISIBILITY_SHOWN, self::VISIBILITY_HIDDEN];
    }
}
