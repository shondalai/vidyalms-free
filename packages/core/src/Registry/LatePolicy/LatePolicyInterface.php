<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\LatePolicy;

use Shondalai\VidyaLMS\Core\Registry\Context\LatePolicyContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * What a late submission costs.
 *
 * The free build ships "none", and it ships a free code path that reaches it:
 * courses.late_policy_key and courses.late_policy_params exist because the
 * only other storage is on a paid table, and a free provider nothing calls is
 * the same failure as an empty seam one step further in.
 *
 * The miss direction is published: an unresolved policy falls back to none
 * and records the substitution on the grade row, so a removed package
 * degrades to no penalty rather than to an unreadable grade.
 */
interface LatePolicyInterface extends ProviderInterface
{
    public function label(): string;

    public function apply(LateOutcomeInput $input, LatePolicyContext $context): LateOutcome;

    public function currentShape(): int;
}
