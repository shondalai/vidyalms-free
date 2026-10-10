<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Shape;

/**
 * A provider that knows which shape its stored parameters are in.
 *
 * It is a capability interface probed with instanceof rather than a method on
 * the four seat provider interfaces, because those already ship with free
 * providers and an interface that has shipped may not gain a method. A
 * provider that does not declare it stores its parameters at shape 1, which is
 * what a first shape is; one that does declare it says so and the version
 * column beside the parameters records the answer, so a later replacement of
 * the same key knows which shape it is reading rather than guessing from the
 * keys present.
 *
 * The version lives on a sibling column and never inside the payload for these
 * two columns, so {@see StoredShape::wrap()} is not used here: a provider
 * reads its own parameters unchanged and the shape travels beside them.
 */
interface DeclaresParamsShape
{
    /**
     * The shape this provider writes today, which is a whole number above
     * zero. Zero is reserved for a row written before a version was stored.
     */
    public function currentShape(): int;
}
