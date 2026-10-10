<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityOutcome;
use Shondalai\VidyaLMS\Core\Registry\Availability\ConditionEvaluationRequest;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;

/**
 * The batch answer for a provider whose per-item work is already free once
 * its facts are loaded.
 *
 * It is a real implementation of the batch interface and not a shortcut
 * around it: what the batch phase buys is one dispatch per provider with the
 * fact sets already preloaded, so a date leaf on sixty items costs sixty
 * comparisons and no queries. A provider whose per-item work is a query
 * writes its own evaluateMany() instead.
 */
trait EvaluatesManyByEvaluate
{
    /**
     * @param array<string, ConditionEvaluationRequest> $requests
     *
     * @return array<string, AvailabilityOutcome>
     */
    public function evaluateMany(array $requests, AvailabilityContext $context): array
    {
        $outcomes = [];

        foreach ($requests as $leafKey => $request) {
            $outcomes[$leafKey] = $this->evaluate(
                $request->payload,
                $request->negated,
                $context->forSubject($request->subject),
            );
        }

        return $outcomes;
    }

    /**
     * @param array<string, mixed> $payload
     */
    abstract public function evaluate(array $payload, bool $negated, AvailabilityContext $context): AvailabilityOutcome;
}
