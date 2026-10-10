<?php

declare(strict_types=1);

/**
 * VidyaLMS: the commerce port, catalogue questions answered by filters.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Port;

use Shondalai\VidyaLMS\Core\Domain\Commerce\Money;
use Shondalai\VidyaLMS\Core\Port\Commerce\AnswersPriceViews;
use Shondalai\VidyaLMS\Core\Port\Commerce\CommercePort;
use Shondalai\VidyaLMS\Core\Port\Commerce\ContextAwareCommercePort;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use Shondalai\VidyaLMS\Core\Port\Commerce\PriceOption;
use Shondalai\VidyaLMS\Core\Port\Commerce\PriceView;
use Shondalai\VidyaLMS\Core\Registry\Context\CommerceContext;

defined('ABSPATH') || exit;

/**
 * Catalogue questions answered by whichever shop sells the course.
 *
 * The plugin knows no shop. It asks, and the WooCommerce bridge (or any other
 * shop plugin) answers:
 *
 *   add_filter('vidyalms_course_price', function ($price, $courseId) {
 *       return ['amount_minor' => 249900, 'currency' => 'INR', 'formatted' => '2,499'];
 *   }, 10, 2);
 *
 * A CoursePrice object is accepted as well as the array. No answer means the
 * course is not for sale through a shop, which is not an error: the free build
 * enrols people through open and manual rules without any shop at all.
 *
 * Whatever sells the course, the result is an entitlement written back into the
 * core by the shop-side plugin. This port never grants access.
 *
 * **The jurisdiction crosses as a third filter argument.** Both filters now
 * pass the map CommerceContext::toEventArguments() publishes (locale,
 * currency, country, subdivision, buyer_kind, cms_user_id, background,
 * country_guessed, correlation_id), so a shop plugin can price for the country
 * the storefront resolved rather than for the seller's own. This is
 * backward compatible by WordPress's own rule: a callback registered with
 * accepted_args of 2 is still called with two, so a plugin written before
 * this is unaffected and its answer is marked provisional, which is what
 * stops a price computed with no resolved jurisdiction being shown as final
 * under Directive 98/6/EC or the UK DMCCA 2024.
 *
 * A plugin that has priced for the stated country says so by answering
 * 'provisional' => false. That is honoured only when the context's own
 * jurisdiction is settled, so a plugin cannot promise finality about a country
 * the storefront only guessed.
 */
final class WpCommerce implements CommercePort, ContextAwareCommercePort
{
    use AnswersPriceViews;

    /**
     * Filter: (CoursePrice|array|null $price, int $courseId, array $context = []): CoursePrice|array|null
     */
    public const PRICE_FILTER = 'vidyalms_course_price';

    /**
     * Filter: (string|null $url, int $courseId, array $context = []): string|null
     */
    public const PURCHASE_URL_FILTER = 'vidyalms_course_purchase_url';

    /** The key this bridge answers under, so a storefront can say who priced a course. */
    public const SOURCE_KEY = 'cms-shop';

    public function priceForCourse(int $courseId): ?CoursePrice
    {
        /** @var mixed $answer */
        $answer = apply_filters('vidyalms_course_price', null, $courseId, []);

        return self::priceFrom($answer);
    }

    public function purchaseUrlForCourse(int $courseId): ?string
    {
        return self::urlFrom(apply_filters('vidyalms_course_purchase_url', null, $courseId, []));
    }

    /**
     * The same two filters, asked under a resolved jurisdiction.
     *
     * This overrides the shipped AnswersPriceViews delegation rather than
     * taking it, because the trait's default answers the legacy price and can
     * carry no country to the plugin at all. The trait is still used for
     * priceViewsForCourses(), whose loop calls this method.
     */
    public function priceViewForCourse(int $courseId, CommerceContext $context): ?PriceView
    {
        $arguments = $context->toEventArguments();

        /** @var mixed $answer */
        $answer = apply_filters('vidyalms_course_price', null, $courseId, $arguments);
        $price = self::priceFrom($answer);

        if ($price === null) {
            return null;
        }

        $claimsFinal = \is_array($answer) && ($answer['provisional'] ?? null) === false;

        return new PriceView(
            new PriceOption(
                'headline',
                new Money($price->amountMinor, $price->currency),
                purchaseUrl: self::urlFrom(apply_filters('vidyalms_course_purchase_url', null, $courseId, $arguments)),
                provisional: !($claimsFinal && $context->jurisdictionIsSettled()),
            ),
            resolvedCountry: $context->country,
            resolvedSubdivision: $context->subdivision,
            sourceKey: self::SOURCE_KEY,
        );
    }

    private static function urlFrom(mixed $answer): ?string
    {
        if (!\is_string($answer)) {
            return null;
        }

        $url = trim($answer);

        return $url === '' ? null : $url;
    }

    private static function priceFrom(mixed $answer): ?CoursePrice
    {
        if ($answer instanceof CoursePrice) {
            return $answer;
        }

        if (!\is_array($answer)) {
            return null;
        }

        $amount = $answer['amount_minor'] ?? null;
        $currency = $answer['currency'] ?? null;

        if (!is_numeric($amount) || !\is_string($currency) || $currency === '') {
            return null;
        }

        $formatted = $answer['formatted'] ?? null;

        return new CoursePrice((int) $amount, $currency, \is_string($formatted) ? $formatted : null);
    }
}
