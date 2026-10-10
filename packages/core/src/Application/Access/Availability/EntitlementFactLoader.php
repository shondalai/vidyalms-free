<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Context\AvailabilityContext;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgEntitlementBoundary;

/**
 * Every entitlement this learner holds in the render set's courses,
 * expired ones included.
 *
 * The expired rows are load-bearing rather than noise: an enrolment source
 * leaf must tell "never held one" from "held one that lapsed", because the
 * first is an administrative exclusion and the second is reached by the clock
 * and may not poison a cached exclusion set.
 */
final class EntitlementFactLoader implements AvailabilityFactLoaderInterface
{
    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function factKey(): string
    {
        return AvailabilityContext::FACT_ENTITLEMENTS;
    }

    public function load(AvailabilityContext $context, array $courseIds): mixed
    {
        if ($courseIds === [] || $context->principal->isGuest()) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('entitlements')
                ->where('user_id', '=', $context->principal->userId)
                ->whereIn('course_id', $courseIds),
        );

        return OrgEntitlementBoundary::retain($this->database, $rows, $context->now->format('Y-m-d H:i:s'), true);
    }
}
