<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Scoring;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\BaseType;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\Cardinality;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;

/**
 * The shape lists the free policies bind to, built once so a policy states
 * its binding rather than repeating an enumeration.
 */
final class ScoringShapes
{
    private function __construct()
    {
    }

    /**
     * Every non-record shape whose base type is not a float.
     *
     * match_correct applies to any shape except those, because the
     * specification explicitly warns that template must not be used for
     * numerical equality of floats: an author who reaches for it on a
     * numeric item wants numeric_tolerance and would otherwise get exact
     * binary equality.
     *
     * @return list<ResponseShape>
     */
    public static function exactComparable(): array
    {
        $shapes = [];

        foreach ([Cardinality::Single, Cardinality::Multiple, Cardinality::Ordered] as $cardinality) {
            foreach (BaseType::cases() as $baseType) {
                if ($baseType->isFloatingPoint()) {
                    continue;
                }

                $shapes[] = ResponseShape::of($cardinality, $baseType);
            }
        }

        return $shapes;
    }

    /**
     * The containers a selection policy marks: several identifiers out of a
     * list.
     *
     * @return list<ResponseShape>
     */
    public static function identifierContainers(): array
    {
        return [
            ResponseShape::of(Cardinality::Multiple, BaseType::Identifier),
            ResponseShape::of(Cardinality::Multiple, BaseType::StringValue),
        ];
    }

    /**
     * The containers an ordering policy marks.
     *
     * @return list<ResponseShape>
     */
    public static function orderedContainers(): array
    {
        return [
            ResponseShape::of(Cardinality::Ordered, BaseType::Identifier),
            ResponseShape::of(Cardinality::Ordered, BaseType::StringValue),
        ];
    }

    /**
     * Where a mapping policy applies: any single or multiple container of
     * discrete values. Not applicable to record cardinality.
     *
     * @return list<ResponseShape>
     */
    public static function mappable(): array
    {
        return [
            ResponseShape::of(Cardinality::Single, BaseType::Identifier),
            ResponseShape::of(Cardinality::Single, BaseType::StringValue),
            ResponseShape::of(Cardinality::Multiple, BaseType::Identifier),
            ResponseShape::of(Cardinality::Multiple, BaseType::StringValue),
            ResponseShape::of(Cardinality::Multiple, BaseType::Pair),
            ResponseShape::of(Cardinality::Multiple, BaseType::DirectedPair),
            ResponseShape::of(Cardinality::Ordered, BaseType::Identifier),
            // A record too, and per_target already binds one. A record is a
            // set of members per field, which is exactly what a mapping sums
            // over, and the type applies the map per field. Without it the
            // design's own hottext rule was unofferable on the type that
            // needs it: an inline selection is a record of gaps, hottext
            // defaults to the mapping idiom because a multi-valued gap under
            // exact set equality pays nothing for partial knowledge and
            // under containment pays full marks for clicking every span, and
            // per_target is refused on such a gap outright. So the preset the
            // design names for it did not compile and vanished from the
            // picker with nothing reporting it.
            ResponseShape::anyRecord(),
        ];
    }

    /**
     * Where a text matcher applies.
     *
     * @return list<ResponseShape>
     */
    public static function textual(): array
    {
        return [ResponseShape::of(Cardinality::Single, BaseType::StringValue)];
    }
}
