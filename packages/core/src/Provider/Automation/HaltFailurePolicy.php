<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Registry\Automation\FailureDisposition;
use Shondalai\VidyaLMS\Core\Registry\Automation\FailurePolicyInterface;

/**
 * Stop the run. Later steps do not execute.
 *
 * The retry ladder still runs: the attempts happen before the stop, which is
 * why halt is not one of the two dispositions that make a ladder inert.
 */
final class HaltFailurePolicy implements FailurePolicyInterface
{
    public const KEY = 'halt';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.automation.failure.halt';
    }

    public function disposition(): FailureDisposition
    {
        return FailureDisposition::Halt;
    }
}
