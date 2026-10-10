<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Automation;

use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPlan;
use Shondalai\VidyaLMS\Core\Registry\Automation\RetryPolicyInterface;

/**
 * The published Svix ladder: immediate, 5s, 5m, 30m, 2h, 5h, 10h, 10h.
 *
 * It is registered by name rather than baked into the runner precisely so a
 * site owner can pick a gentler one, and its cumulative attempts landing past
 * two, seven, seventeen and twenty-seven hours are the reason the automation
 * idempotency scope needs an outcome window measured in days: an attempt
 * arriving after the outcome expired would run the effect a second time.
 *
 * The shipped default for any step whose action reaches something outside this
 * site, where anything other than a 2xx inside a bounded timeout counts as a
 * failure, including a 3xx.
 */
final class ExternalDefaultRetry implements RetryPolicyInterface
{
    public const KEY = 'external_default';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'vidyalms.automation.retry.external_default';
    }

    public function plan(): RetryPlan
    {
        return RetryPlan::of([5, 300, 1800, 7200, 18000, 36000, 36000], 8);
    }
}
