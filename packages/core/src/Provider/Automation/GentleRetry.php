<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPlan;
use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPolicyInterface;

/**
 * Three attempts over a quarter of an hour, for an effect inside this site.
 *
 * A local write that failed twice inside fifteen minutes has a cause a person
 * needs to see, and a twenty-seven hour ladder in front of that person is a
 * day of silence rather than a resilience.
 */
final class GentleRetry implements RetryPolicyInterface
{
    public const KEY = 'gentle';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.automation.retry.gentle';
    }

    public function plan(): RetryPlan
    {
        return RetryPlan::of([60, 840], 3);
    }
}
