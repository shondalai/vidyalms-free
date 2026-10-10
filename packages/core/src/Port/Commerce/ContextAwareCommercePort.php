<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Commerce;

use Shondalai\VidyaLMS\Core\Registry\Context\CommerceContext;

/**
 * The host's shop bridge can be asked a price under a resolved jurisdiction.
 *
 * This is the port half of the catalogue widening, and it is a capability
 * interface rather than two new methods on CommercePort for the reason
 * Ports.php already states about its own defaulted members: a required new
 * member turns a core upgrade into a fatal on every site whose adapter has
 * not been rebuilt. PHP interfaces carry no default bodies, so "widened with
 * a default implementation" is expressed as this interface plus the
 * {@see AnswersPriceViews} trait that implements it by delegating, and
 * {@see \Shondalai\VidyaLMS\Core\Application\Commerce\CommercePrices} performs
 * the identical delegation for a bridge that declares neither.
 *
 * **Default when absent:** the legacy priceForCourse() and
 * purchaseUrlForCourse() are used and the resulting view is marked
 * provisional, because a price computed with no resolved jurisdiction may not
 * be presented as a final consumer price under Directive 98/6/EC or the UK
 * DMCCA 2024.
 *
 * The context is carried into the host's own event or filter through
 * CommerceContext::toEventArguments(), which is the one published shape both
 * adapters pass to the shop plugin on the other side. Without that last part
 * the free provider has a context it cannot send anywhere.
 */
interface ContextAwareCommercePort
{
    public function priceViewForCourse(int $courseId, CommerceContext $context): ?PriceView;

    /**
     * @param list<int> $courseIds
     *
     * @return array<int, PriceView> keyed by course id; a course the shop does
     *                               not sell is simply absent
     */
    public function priceViewsForCourses(array $courseIds, CommerceContext $context): array;
}
