<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\LatePolicy;

use Shondalai\VidyaLMS\Core\Registry\Context\LatePolicyContext;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LateOutcome;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LateOutcomeInput;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LatePolicyInterface;

/**
 * No penalty: a late submission scores what it scored.
 *
 * It is the free provider of its registry and the fallback of its miss, and
 * it has a free code path that reaches it because a course carries a late
 * policy key and its parameters. Without those columns the only storage
 * would be on a paid table, and a free provider nothing can call is the same
 * empty seam one step further in.
 */
final class NoLatePolicy implements LatePolicyInterface
{
    public const KEY = 'none';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.late_policy.none';
    }

    public function apply(LateOutcomeInput $input, LatePolicyContext $context): LateOutcome
    {
        return new LateOutcome($input->rawPercentage, self::KEY);
    }

    public function currentShape(): int
    {
        return 1;
    }
}
