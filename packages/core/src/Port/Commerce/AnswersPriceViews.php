<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Commerce;

use Shondalai\VidyaLMS\Core\Domain\Commerce\Money;
use Shondalai\VidyaLMS\Core\Registry\Context\CommerceContext;

/**
 * The shipped default implementation of ContextAwareCommercePort.
 *
 * An adapter that has nothing better to offer yet declares the capability and
 * uses this trait: the answer is the legacy price, marked provisional, which
 * is the documented default rather than a refusal. An adapter whose shop
 * plugin does implement the context-taking ask overrides both methods and
 * answers a real jurisdiction-aware view.
 *
 * The delegation lives in a trait rather than in an abstract base class
 * because both host adapters already extend nothing and a port implementation
 * is not a hierarchy.
 */
trait AnswersPriceViews
{
    public function priceViewForCourse(int $courseId, CommerceContext $context): ?PriceView
    {
        $price = $this->priceForCourse($courseId);

        if ($price === null) {
            return null;
        }

        return new PriceView(
            new PriceOption(
                'headline',
                new Money($price->amountMinor, $price->currency),
                purchaseUrl: $this->purchaseUrlForCourse($courseId),
                provisional: true,
            ),
        );
    }

    /**
     * @param list<int> $courseIds
     *
     * @return array<int, PriceView>
     */
    public function priceViewsForCourses(array $courseIds, CommerceContext $context): array
    {
        $views = [];

        foreach ($courseIds as $courseId) {
            $view = $this->priceViewForCourse($courseId, $context);

            if ($view !== null) {
                $views[$courseId] = $view;
            }
        }

        return $views;
    }

    abstract public function priceForCourse(int $courseId): ?CoursePrice;

    abstract public function purchaseUrlForCourse(int $courseId): ?string;
}
