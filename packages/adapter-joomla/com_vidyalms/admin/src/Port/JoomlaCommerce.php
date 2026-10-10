<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Port;

defined('_JEXEC') or die;

use Joomla\CMS\Event\GenericEvent;
use Joomla\Event\DispatcherInterface;
use Shondalai\VidyaLMS\Core\Domain\Commerce\Money;
use Shondalai\VidyaLMS\Core\Port\Commerce\AnswersPriceViews;
use Shondalai\VidyaLMS\Core\Port\Commerce\CommercePort;
use Shondalai\VidyaLMS\Core\Port\Commerce\ContextAwareCommercePort;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use Shondalai\VidyaLMS\Core\Port\Commerce\PriceOption;
use Shondalai\VidyaLMS\Core\Port\Commerce\PriceView;
use Shondalai\VidyaLMS\Core\Registry\Context\CommerceContext;

/**
 * Catalogue questions answered by whichever shop sells the course.
 *
 * The component knows no shop. It asks, and an EasyCommerce, J2Commerce or
 * HikaShop plugin answers by pushing onto the event's "result" argument:
 *
 *   public function onVidyaLmsGetCoursePrice(GenericEvent $event): void
 *   {
 *       $result = $event->getArgument('result', []);
 *       $result[] = ['amount_minor' => 249900, 'currency' => 'INR', 'formatted' => '2,499'];
 *       $event->setArgument('result', $result);
 *   }
 *
 * The first usable answer wins; no answer means the course is not for sale
 * through a shop, which is not an error.
 *
 * **The jurisdiction crosses on the same event.** Both events now carry the
 * keys CommerceContext::toEventArguments() publishes (locale, currency,
 * country, subdivision, buyer_kind, cms_user_id, background, country_guessed,
 * correlation_id), as individual arguments and as a "context" map, so a shop
 * plugin can price for the country the storefront resolved rather than for
 * the seller's own. A plugin written before this reads none of them and is
 * unaffected: the event's existing courseId and result arguments are
 * unchanged, and its answer is marked provisional, which is what stops a
 * price computed with no resolved jurisdiction being shown as final under
 * Directive 98/6/EC or the UK DMCCA 2024.
 *
 * A plugin that has priced for the stated country says so by answering
 * 'provisional' => false. That is honoured only when the context's own
 * jurisdiction is settled, so a plugin cannot promise finality about a
 * country the storefront only guessed.
 *
 * @since 1.0.0
 */
final class JoomlaCommerce implements CommercePort, ContextAwareCommercePort
{
    use AnswersPriceViews;

    public const PRICE_EVENT = 'onVidyaLmsGetCoursePrice';

    public const PURCHASE_URL_EVENT = 'onVidyaLmsGetCoursePurchaseUrl';

    /** The key this bridge answers under, so a storefront can say who priced a course. */
    public const SOURCE_KEY = 'cms-shop';

    public function __construct(
        private readonly DispatcherInterface $dispatcher,
    ) {
    }

    public function priceForCourse(int $courseId): ?CoursePrice
    {
        foreach ($this->ask(self::PRICE_EVENT, $courseId) as $answer) {
            $price = $this->priceFrom($answer);

            if ($price !== null) {
                return $price;
            }
        }

        return null;
    }

    public function purchaseUrlForCourse(int $courseId): ?string
    {
        foreach ($this->ask(self::PURCHASE_URL_EVENT, $courseId) as $answer) {
            if (\is_string($answer) && $answer !== '') {
                return $answer;
            }
        }

        return null;
    }

    /**
     * The same two events, asked under a resolved jurisdiction.
     *
     * This overrides the shipped AnswersPriceViews delegation rather than
     * taking it, because the trait's default answers the legacy price and can
     * carry no country to the plugin at all. The trait is still used for
     * priceViewsForCourses(), whose loop calls this method.
     */
    public function priceViewForCourse(int $courseId, CommerceContext $context): ?PriceView
    {
        $answered = null;

        foreach ($this->ask(self::PRICE_EVENT, $courseId, $context) as $answer) {
            $price = $this->priceFrom($answer);

            if ($price !== null) {
                $answered = [$price, \is_array($answer) ? $answer : []];

                break;
            }
        }

        if ($answered === null) {
            return null;
        }

        [$price, $raw] = $answered;
        $claimsFinal = ($raw['provisional'] ?? null) === false;

        return new PriceView(
            new PriceOption(
                'headline',
                new Money($price->amountMinor, $price->currency),
                purchaseUrl: $this->purchaseUrlFor($courseId, $context),
                provisional: !($claimsFinal && $context->jurisdictionIsSettled()),
            ),
            resolvedCountry: $context->country,
            resolvedSubdivision: $context->subdivision,
            sourceKey: self::SOURCE_KEY,
        );
    }

    private function purchaseUrlFor(int $courseId, CommerceContext $context): ?string
    {
        foreach ($this->ask(self::PURCHASE_URL_EVENT, $courseId, $context) as $answer) {
            if (\is_string($answer) && $answer !== '') {
                return $answer;
            }
        }

        return null;
    }

    /**
     * @return list<mixed>
     */
    private function ask(string $eventName, int $courseId, ?CommerceContext $context = null): array
    {
        $arguments = ['courseId' => $courseId, 'result' => []];

        if ($context !== null) {
            // Both shapes on purpose. A plugin reading one argument at a time
            // asks for 'country'; a plugin passing the whole ask to its own
            // pricing service reads 'context'. Neither collides with the two
            // arguments the event already carried.
            $arguments += $context->toEventArguments();
            $arguments['context'] = $context->toEventArguments();
        }

        $event = new GenericEvent($eventName, $arguments);
        $this->dispatcher->dispatch($eventName, $event);

        $results = $event->getArgument('result', []);

        return \is_array($results) ? array_values($results) : [];
    }

    private function priceFrom(mixed $answer): ?CoursePrice
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
