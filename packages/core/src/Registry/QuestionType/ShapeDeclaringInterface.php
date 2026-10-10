<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Response\ResponseShape;

/**
 * The response shape a type produces, for a type that declares it without
 * taking on the whole definition contract.
 *
 * The default when absent is single/string, which is the conservative
 * reading and what the generic fallback renderer shows.
 */
interface ShapeDeclaringInterface
{
    public function responseShape(): ResponseShape;
}
