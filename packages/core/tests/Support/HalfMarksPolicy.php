<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Support;

use Shondalai\VidyaLMS\Core\Registry\Context\LatePolicyContext;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LateOutcome;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LateOutcomeInput;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LatePolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LateStatus;

/**
 * A late policy that halves whatever it is handed.
 *
 * It exists so a test can measure that the grade write consulted the registry
 * and used its answer, rather than that a particular arithmetic is right: the
 * registry had no call site at all, so the free provider and all four paid
 * ones were classes nothing drove.
 */
final class HalfMarksPolicy implements LatePolicyInterface
{
    public const KEY = 'acme.half_marks';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return self::KEY;
    }

    public function apply(LateOutcomeInput $input, LatePolicyContext $context): LateOutcome
    {
        return new LateOutcome(
            $input->rawPercentage / 2,
            self::KEY,
            [],
            null,
            LateStatus::Late,
            'acme.half_marks.applied',
        );
    }

    public function currentShape(): int
    {
        return 1;
    }
}
