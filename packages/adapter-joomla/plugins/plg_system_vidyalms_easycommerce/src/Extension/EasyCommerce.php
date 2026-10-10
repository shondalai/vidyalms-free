<?php

declare(strict_types=1);

namespace VidyaLMS\Plugin\System\VidyaLmsEasyCommerce\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Event\Application\AfterRespondEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce\Integration;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce\PostResponseSync;

/** Shop notifications are hints; only the reconciliation worker changes LMS access. */
final class EasyCommerce extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    public function __construct(array $config = [], private ?PostResponseSync $postResponseSync = null)
    {
        parent::__construct($config);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRespond' => 'onAfterRespond',
            'onVidyaLmsGetCoursePrice' => 'onPrice',
            'onVidyaLmsGetCoursePurchaseUrl' => 'onPurchaseUrl',
            'onEasyCommerceAfterCheckoutValidate' => 'onCheckoutValidate',
            'onEasyCommerceBeforeOrderCreate' => 'onBeforeOrderCreate',
            'onEasyCommerceAfterOrderCreate' => 'onOrder',
            'onEasyCommerceAfterOrderPayment' => 'onOrder',
            'onEasyCommerceAfterOrderStatusChange' => 'onOrder',
            'onEasyCommerceAfterOrderRefund' => 'onOrder',
            'onEasyCommerceAfterOrderCancel' => 'onOrder',
            'onEasyCommerceAfterOrderDelete' => 'onOrder',
            'onEasyCommerceAfterSubscriptionRenewal' => 'onSubscription',
            'onEasyCommerceAfterSubscriptionExpired' => 'onSubscription',
            'onEasyCommerceAfterSubscriptionPaused' => 'onSubscription',
            'onEasyCommerceAfterSubscriptionResumed' => 'onSubscription',
            'onEasyCommerceAfterSubscriptionPlanChanged' => 'onSubscription',
        ];
    }

    public function onOrder(Event $event): void
    {
        $order = (array) $event->getArgument('order', []);
        $this->enqueue('order', (int) ($order['id'] ?? 0));
        $this->enqueue('subscription', (int) ($order['subscription_id'] ?? 0));
    }

    public function onSubscription(Event $event): void
    {
        $subscription = (array) $event->getArgument('subscription', []);
        $this->enqueue('subscription', (int) ($subscription['id'] ?? 0));
        $this->enqueue('order', (int) ($subscription['order_id'] ?? 0));
    }

    public function onAfterRespond(AfterRespondEvent $event): void
    {
        if ($this->postResponseSync === null || !$this->ready()) {
            return;
        }
        $app = $event->getApplication();
        if (!$app instanceof CMSApplicationInterface) {
            return;
        }
        try {
            $this->postResponseSync->respond($app->isClient('cli'));
        } catch (\Throwable $failure) {
            // The response is already sent. Durable queue rows remain for cron.
            Integration::log($failure, 'after_response');
        }
    }

    public function onCheckoutValidate(Event $event): void
    {
        if (!$this->ready()
            || (int) ($this->getApplication()->getIdentity()->id ?? 0) > 0) {
            return;
        }
        $cart = (array) $event->getArgument('cart', []);
        if (Integration::mapping()->containsMappedItems((array) ($cart['items'] ?? []))) {
            $errors = (array) $event->getArgument('errors', []);
            $errors[] = Text::_('PLG_SYSTEM_VIDYALMS_EASYCOMMERCE_ACCOUNT_REQUIRED');
            $event->setArgument('errors', $errors);
        }
    }

    public function onBeforeOrderCreate(Event $event): void
    {
        if (!$this->ready()) {
            return;
        }
        // This is the cancellable service event, after cart items are assembled.
        // BeforeCheckoutOrderCreate has only addresses, not the purchased items.
        $data = (array) $event->getArgument('data', []);
        $user = array_key_exists('user_id', $data) ? (int) $data['user_id']
            : (int) ($this->getApplication()->getIdentity()->id ?? 0);
        if ($user < 1 && Integration::mapping()->containsMappedItems((array) ($data['items'] ?? []))
            && method_exists($event, 'cancel')) {
            $event->cancel(Text::_('PLG_SYSTEM_VIDYALMS_EASYCOMMERCE_ACCOUNT_REQUIRED'));
        }
    }

    public function onPrice(Event $event): void
    {
        $this->catalogue($event, true);
    }

    public function onPurchaseUrl(Event $event): void
    {
        $this->catalogue($event, false);
    }

    private function catalogue(Event $event, bool $price): void
    {
        if (!$this->ready()) {
            return;
        }
        $mappings = Integration::mapping()->productsFor((int) $event->getArgument('courseId', 0));
        if ($mappings === []) {
            return;
        }
        try {
            $this->getApplication()->bootComponent('com_easycommerce');
            $container = Factory::getContainer();
            $products = $container->get('Joomla\\Component\\EasyCommerce\\Administrator\\Service\\Product\\Contract\\ProductServiceInterface');
            foreach ($mappings as $mapping) {
                $product = $products->getById($mapping['product_id']);
                if (!$product || !$products->isPurchasable($mapping['product_id']) || $products->getPublicById($mapping['product_id']) === null) {
                    continue;
                }
                $answer = Route::_('index.php?option=com_easycommerce&view=product&id=' . $mapping['product_id'], false);
                if ($price) {
                    $pricing = $container->get('Joomla\\Component\\EasyCommerce\\Administrator\\Service\\Pricing\\Contract\\PricingServiceInterface');
                    // EasyCommerce owns price selection/formatting. Its API's currency
                    // argument does not convert amounts, so request the store currency.
                    $quote = $pricing->getProductPrice($product, 1, '', ['variation_id' => $mapping['variation_id']]);
                    $currency = (string) $quote['currency'];
                    $currencyRow = $pricing->getCurrency($currency);
                    if ($currencyRow === null) {
                        continue;
                    }
                    $amount = (float) $quote['price'];
                    $decimals = max(0, min(4, (int) $currencyRow->decimal_places));
                    $answer = [
                        'amount_minor' => (int) round($amount * (10 ** $decimals)),
                        'currency' => $currency,
                        'formatted' => $pricing->formatPrice($amount, $currency),
                        'provisional' => true,
                    ];
                }
                $results = (array) $event->getArgument('result', []);
                $results[] = $answer;
                $event->setArgument('result', $results);
                return;
            }
        } catch (\Throwable $failure) {
            Integration::log($failure, 'catalogue');
        }
    }

    private function enqueue(string $type, int $id): void
    {
        if ($id < 1 || !$this->ready()) {
            return;
        }
        try {
            Integration::enqueue($type, $id);
            $this->postResponseSync ??= new PostResponseSync();
            $this->postResponseSync->queued($type, $id);
        } catch (\Throwable $failure) {
            // Source scanning repairs a missed hint, even after a shop transaction
            // or an installer has rolled back. Never interrupt a confirmed payment.
            Integration::log($failure, $type . ':' . $id);
        }
    }

    private function ready(): bool
    {
        return class_exists(Integration::class) && Integration::available();
    }
}
