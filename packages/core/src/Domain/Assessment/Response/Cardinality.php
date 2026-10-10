<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Response;

/**
 * QTI response cardinality, pinned behind a core enum rather than spread as
 * string literals across every type schema.
 *
 * The design's reason for the enum is architectural: QTI's vocabulary is
 * load-bearing throughout the assessment model, and a future revision of it
 * must produce a compile-time list of affected call sites instead of a
 * grep.
 */
enum Cardinality: string
{
    case Single = 'single';
    case Multiple = 'multiple';
    case Ordered = 'ordered';
    case Record = 'record';

    public static function tryFromWire(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /**
     * Whether member order carries meaning. Set equality is the comparison
     * rule everywhere this is false.
     */
    public function isOrdered(): bool
    {
        return $this === self::Ordered;
    }

    public function isContainer(): bool
    {
        return $this === self::Multiple || $this === self::Ordered;
    }
}
