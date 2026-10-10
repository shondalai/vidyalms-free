<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * How a failed automation step backs off before it is tried again.
 *
 * A stored provider key on automation_rule_actions, which is why it is a
 * registry: a const ladder cannot be replaced by a site that needs a
 * different one, and the save-time validation that refuses an inert
 * retry-plus-continue pairing has to read a registry rather than a list.
 */
interface RetryPolicyInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The ladder, the cap and the jitter, as one value object.
     *
     * One method rather than three getters, so a plan that later has to say
     * something more is an additive field on a value object rather than a
     * fourth method on shipped bytes a third party implements.
     */
    public function plan(): RetryPlan;
}
