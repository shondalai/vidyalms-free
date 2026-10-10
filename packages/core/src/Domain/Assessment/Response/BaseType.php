<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Response;

/**
 * QTI base types, pinned behind a core enum for the same reason Cardinality
 * is. Only the members this catalogue can produce are listed; adding one is
 * additive and touches the exporter, never a type.
 */
enum BaseType: string
{
    case Identifier = 'identifier';
    case Boolean = 'boolean';
    case Integer = 'integer';
    case Float = 'float';
    case StringValue = 'string';
    case Point = 'point';
    case Pair = 'pair';
    case DirectedPair = 'directed_pair';
    case Duration = 'duration';
    case File = 'file';
    case Uri = 'uri';

    public static function tryFromWire(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /**
     * The two types match_correct refuses, because the specification warns
     * that template against numerical equality of floats.
     */
    public function isFloatingPoint(): bool
    {
        return $this === self::Float;
    }
}
