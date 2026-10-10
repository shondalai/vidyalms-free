<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Commerce;

use Shondalai\VidyaLMS\Core\Domain\Commerce\AccessTerm;
use Shondalai\VidyaLMS\Core\Domain\Commerce\BillingTerm;
use Shondalai\VidyaLMS\Core\Domain\Commerce\Money;
use Shondalai\VidyaLMS\Core\Domain\Commerce\TaxTreatment;

/**
 * One way to buy one course.
 *
 * CoursePrice is not grown by field accretion: it can express exactly one
 * number in one currency with no tax treatment, no duration, no recurrence
 * and no was-now pair, so a rental, a subscription price, a coupon-adjusted
 * price and a seat price are all inexpressible in it. This is added beside it
 * and CoursePrice is derived from the headline option, so nothing that
 * consumes it breaks.
 *
 * The access term is carried here rather than rendered into the label,
 * because the drawn "12 months' access" subline has to be data for a
 * translator and a screen reader to do anything sensible with it.
 *
 * `provisional` is the honest half of the free-build claim. A price computed
 * with no resolved jurisdiction, or by a shop plugin that has not implemented
 * the context-taking method, is marked provisional and the storefront says so,
 * because Directive 98/6/EC and the UK DMCCA 2024 are about the number a
 * consumer is shown.
 */
final class PriceOption
{
    public function __construct(
        public readonly string $optionKey,
        public readonly Money $amount,
        public readonly TaxTreatment $tax = TaxTreatment::Undetermined,
        public readonly ?Money $wasAmount = null,
        public readonly ?BillingTerm $recurring = null,
        public readonly ?AccessTerm $access = null,
        /** A translation key or a seller-authored plan name, never a rendered sentence built here. */
        public readonly string $label = '',
        public readonly ?string $purchaseUrl = null,
        public readonly bool $provisional = false,
    ) {
        if ($optionKey === '') {
            throw new \InvalidArgumentException('A price option carries a stable key the catalogue can reference.');
        }

        if ($wasAmount !== null && $wasAmount->currency !== $amount->currency) {
            throw new \InvalidArgumentException('A was-now pair is denominated in one currency.');
        }
    }

    /** The narrow shape everything that predates PriceView still consumes. */
    public function toCoursePrice(?string $formatted = null): CoursePrice
    {
        return new CoursePrice($this->amount->minorUnits, $this->amount->currency, $formatted);
    }
}
