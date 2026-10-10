<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Commerce;

use Shondalai\VidyaLMS\Core\Port\Commerce\PriceView;
use Shondalai\VidyaLMS\Core\Registry\Context\CommerceContext;

/**
 * The commerce source can price many courses in one ask.
 *
 * Putting a headline price option on the catalogue list shape reintroduces
 * exactly the N+1 the catalogue contract exists to remove: a twenty-four card
 * catalogue page otherwise dispatches twenty-four events into the Joomla shop
 * plugin or runs twenty-four filter passes into the WordPress one.
 *
 * **Default when absent:** the catalogue loops one course at a time, which is
 * that N+1, and the storefront logs it rather than hiding it. A slow page
 * nobody can see the cause of is worse than a slow page with a line in the
 * log naming the source that could not batch.
 *
 * @see ContextAwareCommerceSource for the per-course shape and its own default
 */
interface BatchPricesCourses
{
    /**
     * @param list<array<string, mixed>> $courses
     *
     * @return array<int, PriceView> keyed by course id; a course this source
     *                               does not sell is simply absent
     */
    public function priceViewsFor(array $courses, CommerceContext $context): array;
}
