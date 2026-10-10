<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Commerce;

use Shondalai\VidyaLMS\Core\Port\Commerce\PriceView;
use Shondalai\VidyaLMS\Core\Registry\Context\CommerceContext;

/**
 * The commerce source can price against a resolved jurisdiction.
 *
 * A capability interface probed with instanceof over the untouched
 * CommerceSourceInterface, never a method added to it: third parties are
 * invited to implement that contract and a new method breaks every one of
 * them.
 *
 * It exists because one catalogue has to render inclusive in the EU and the
 * UK and exclusive in the US. Directive 98/6/EC requires the selling price
 * shown to a consumer to be the final price including VAT, Article 6(1)(e) of
 * Directive 2011/83/EU requires the total inclusive price before the consumer
 * is bound, and the UK Digital Markets, Competition and Consumers Act 2024
 * makes a headline price that omits a mandatory charge an unfair commercial
 * practice in all circumstances. The shipped priceFor(array $course) carries
 * no country, no currency, no buyer kind and no locale, so a
 * jurisdiction-aware price is not buildable from it.
 *
 * **Default when absent:** the legacy priceFor() and purchaseUrlFor() are
 * used, and the price is rendered with no resolved jurisdiction, which the
 * storefront marks provisional. That is the honest half of the free-build
 * claim: the free provider's price is only as jurisdiction-aware as the shop
 * on the other side of the event or the filter, and promising otherwise would
 * be the kind of claim the design exists to avoid.
 *
 * **It declares one method, and the missing second one is deliberate.** A
 * `purchaseUrlFor(array $course, CommerceContext $context)` here would collide
 * with `CommerceSourceInterface::purchaseUrlFor(array $course)`, which every
 * implementer of this capability also implements: PHP refuses the class at
 * declaration with an incompatible-signature fatal, and the only signature
 * satisfying both is a defaulted `?CommerceContext $context = null` that
 * nothing documents and nothing calls. A jurisdiction-aware checkout link
 * travels on {@see \Shondalai\VidyaLMS\Core\Port\Commerce\PriceOption::$purchaseUrl}
 * instead, which the view this method returns already carries per option,
 * which is where a per-currency or per-country link genuinely belongs.
 */
interface ContextAwareCommerceSource
{
    /**
     * @param array<string, mixed> $course
     */
    public function priceViewFor(array $course, CommerceContext $context): ?PriceView;
}
