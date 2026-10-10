<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPlan;
use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPolicyInterface;

/**
 * One attempt and no ladder.
 *
 * A real answer rather than an absence, and the only pairing in which a
 * continuing failure policy is not the inert combination the save-time check
 * refuses: an author who says "try once, then move on" has said it once, in
 * one place, and both halves agree.
 */
final class NoRetry implements RetryPolicyInterface
{
    public const KEY = 'none';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.automation.retry.none';
    }

    public function plan(): RetryPlan
    {
        return RetryPlan::once();
    }
}
