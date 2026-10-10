<?php

declare(strict_types=1);

/**
 * WooCommerce owns commerce; this bridge only projects its state into access.
 *
 * @package VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license GNU General Public License version 3 or later
 */
namespace Shondalai\VidyaLMS\WordPress\Commerce;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Ports;

defined('ABSPATH') || exit;

final class WooCommerce
{
    public const COURSE_META = '_vidyalms_course_id';
    public const MAPPINGS_OPTION = 'vidyalms_woocommerce_mappings';
    private const SNAPSHOT_OPTION = 'vidyalms_woocommerce_snapshot_';
    private const ERASED_OPTION = 'vidyalms_woocommerce_erased_';
    public const ITEM_META = '_vidyalms_access_snapshot';
    public const COURSES_META = '_vidyalms_access_courses';
    public const ERROR_META = '_vidyalms_access_error';
    public const RETRY_HOOK = 'vidyalms_woocommerce_sync';
    public const JOB_GROUP = 'vidyalms-woocommerce';
    public const RECONCILE_HOOK = 'vidyalms_woocommerce_reconcile';
    public const BATCH_HOOK = 'vidyalms_woocommerce_reconcile_batch';
    public const TRACKED_BATCH_HOOK = 'vidyalms_woocommerce_reconcile_tracked';
    private const COURSE_OPTION_LIMIT = 500;
    private const BATCH_SIZE = 50;

    private ?\Closure $kernelResolver;
    private ?\Closure $portsResolver;
    /** @var array<int, int> */
    private array $productCache = [];
    /** @var array<int, bool> Prevent nested Woo save hooks from re-entering. */
    private array $syncing = [];
    /** @var array<int, array{courses: list<int>, recurring: bool, product: string}> */
    private array $pendingSnapshots = [];

    /** @param callable(): CoreKernel|null $kernelResolver @param callable(): Ports|null $portsResolver */
    public function __construct(?callable $kernelResolver = null, ?callable $portsResolver = null)
    {
        $this->kernelResolver = $kernelResolver === null ? null : \Closure::fromCallable($kernelResolver);
        $this->portsResolver = $portsResolver === null ? null : \Closure::fromCallable($portsResolver);
    }

    public static function boot(?callable $kernelResolver = null, ?callable $portsResolver = null): self
    {
        $bridge = new self($kernelResolver, $portsResolver);
        if (did_action('plugins_loaded') > 0) {
            $bridge->register();
        } else {
            add_action('plugins_loaded', [$bridge, 'register'], 20);
        }
        return $bridge;
    }

    public static function available(): bool
    {
        return class_exists('WooCommerce') && function_exists('wc_get_order');
    }

    public static function accountUrl(): string
    {
        return self::available() && function_exists('wc_get_page_permalink')
            ? (string) wc_get_page_permalink('myaccount') : '';
    }

    public function register(): void
    {
        if (!self::available()) {
            return;
        }
        add_action('woocommerce_product_options_general_product_data', [$this, 'renderProductFields']);
        add_action('woocommerce_admin_process_product_object', [$this, 'saveProductFields']);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'renderVariationFields'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'saveVariationFields'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'snapshotCheckoutItem'], 10, 4);
        add_filter('woocommerce_checkout_registration_required', [$this, 'requireAccount']);
        add_filter('woocommerce_checkout_registration_enabled', [$this, 'requireAccount']);
        add_action('woocommerce_checkout_create_order', [$this, 'validateCheckoutOrder']);
        // Runs after Store API customer creation, before payment processing.
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'validateCheckoutOrder']);
        add_action('woocommerce_payment_complete', [$this, 'paymentCompleted'], 90);
        foreach (['woocommerce_payment_complete', 'woocommerce_order_status_processing',
            'woocommerce_order_status_completed', 'woocommerce_order_status_changed',
            'woocommerce_order_refunded', 'woocommerce_saved_order_items',
            'woocommerce_update_order', 'woocommerce_new_order',
            'woocommerce_subscriptions_switch_completed', 'subscriptions_created_for_order'] as $hook) {
            add_action($hook, [$this, 'queueOrderSync'], 100);
        }
        add_action('woocommerce_refund_deleted', [$this, 'refundDeleted'], 100, 2);
        // Queue before the object/metadata disappears; after-hooks also cover restores.
        foreach (['woocommerce_before_delete_order', 'woocommerce_before_trash_order',
            'woocommerce_delete_order', 'woocommerce_trash_order', 'woocommerce_untrash_order'] as $hook) {
            add_action($hook, [$this, 'queueOrderSync']);
        }
        // Legacy order screens can call WordPress post APIs without Woo CRUD hooks.
        foreach (['before_delete_post', 'deleted_post', 'wp_trash_post', 'trashed_post', 'untrashed_post'] as $hook) {
            add_action($hook, [$this, 'queuePostSync'], 10, 2);
        }
        foreach (['woocommerce_subscription_status_updated', 'woocommerce_subscription_payment_complete',
            'woocommerce_subscription_payment_failed', 'woocommerce_subscription_date_updated',
            'woocommerce_subscription_date_deleted', 'woocommerce_checkout_subscription_created',
            'woocommerce_update_subscription', 'woocommerce_new_subscription'] as $hook) {
            add_action($hook, [$this, 'queueOrderSync'], 100);
        }
        // Item CRUD covers REST/admin item removal and plan changes without a status transition.
        add_action('woocommerce_new_order_item', [$this, 'itemSaved'], 100, 3);
        add_action('woocommerce_update_order_item', [$this, 'itemSaved'], 100, 3);
        add_action('woocommerce_before_delete_order_item', [$this, 'itemDeleting']);
        add_action(self::RETRY_HOOK, [$this, 'syncOrder']);
        add_action(self::RECONCILE_HOOK, [$this, 'reconcile']);
        add_action(self::BATCH_HOOK, [$this, 'reconcile']);
        add_action(self::TRACKED_BATCH_HOOK, [$this, 'reconcileTracked']);
        if (!wp_next_scheduled(self::RECONCILE_HOOK)) {
            wp_schedule_event(time() + 60, 'hourly', self::RECONCILE_HOOK);
        }
        add_filter('vidyalms_course_price', [$this, 'coursePrice'], 10, 2);
        add_filter('vidyalms_course_purchase_url', [$this, 'coursePurchaseUrl'], 10, 2);
    }

    public function renderProductFields(): void
    {
        if (!$this->canManageMappings()) {
            return;
        }
        global $product_object;
        $mappings = (array) get_option(self::MAPPINGS_OPTION, []);
        $selected = $product_object instanceof \WC_Product ? (string) ($mappings[$product_object->get_id()] ?? '') : '';
        echo '<div class="options_group">';
        woocommerce_wp_select([
            'id' => self::COURSE_META,
            'label' => __('Grants access to course', 'vidyalms'),
            'description' => __('Select a course marked Paid in VidyaLMS. A paid order grants access; subscription products grant access for their active term. An account is required.', 'vidyalms'),
            'desc_tip' => true,
            'value' => $selected,
            'options' => $this->courseOptions($selected),
        ]);
        echo '</div>';
    }

    public function renderVariationFields($loop, $variationData, $variation): void
    {
        if (!$this->canManageMappings()) {
            return;
        }
        $product = wc_get_product((int) $variation->ID);
        $mappings = (array) get_option(self::MAPPINGS_OPTION, []);
        $selected = $product instanceof \WC_Product ? (string) ($mappings[$product->get_id()] ?? '') : '';
        woocommerce_wp_select([
            'id' => self::COURSE_META . '_' . (int) $loop,
            'name' => self::COURSE_META . '_variation[' . (int) $loop . ']',
            'label' => __('Grants access to course', 'vidyalms'),
            'value' => $selected,
            'options' => ['' => __('Use parent product mapping', 'vidyalms'), '0' => __('Not a course', 'vidyalms')]
                + $this->courseOptions($selected),
            'wrapper_class' => 'form-row form-row-full',
        ]);
    }

    /** @return array<string|int, string> */
    private function courseOptions(string $selected): array
    {
        $options = ['' => __('Not a course', 'vidyalms')] + $this->courses();
        if ((int) $selected > 0 && !isset($options[$selected]) && $this->courseExists((int) $selected)) {
            /* translators: %d: course ID. */
            $options[$selected] = sprintf(__('Course %d', 'vidyalms'), (int) $selected);
        }
        return $options;
    }

    public function saveProductFields($product): void
    {
        if (!$product instanceof \WC_Product || !$this->canSaveProduct($product)) {
            return;
        }
        // canSaveProduct above verifies the WooCommerce product/variation nonce and both capabilities.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (isset($_POST[self::COURSE_META]) && \is_scalar($_POST[self::COURSE_META])) {
            // canSaveProduct above verifies the WooCommerce product/variation nonce and both capabilities.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $this->saveMapping($product, sanitize_text_field(wp_unslash($_POST[self::COURSE_META])));
        }
    }

    public function saveVariationFields($variationId, $loop): void
    {
        $product = wc_get_product((int) $variationId);
        if (!$product instanceof \WC_Product || !$this->canSaveProduct($product)) {
            return;
        }
        // canSaveProduct above verifies the WooCommerce product/variation nonce and both capabilities.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if (isset($_POST[self::COURSE_META . '_variation'])
            // canSaveProduct above verifies the WooCommerce product/variation nonce and both capabilities.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            && \is_array($_POST[self::COURSE_META . '_variation']) && isset($_POST[self::COURSE_META . '_variation'][$loop])
            // canSaveProduct above verifies the WooCommerce product/variation nonce and both capabilities.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            && \is_scalar($_POST[self::COURSE_META . '_variation'][$loop])) {
            // canSaveProduct above verifies the WooCommerce product/variation nonce and both capabilities.
            // phpcs:ignore WordPress.Security.NonceVerification.Missing
            $value = sanitize_text_field(wp_unslash($_POST[self::COURSE_META . '_variation'][$loop]));
            $this->saveMapping($product, $value);
            $product->save_meta_data();
        }
    }

    private function canSaveProduct(\WC_Product $product): bool
    {
        $nonce = '';
        if (isset($_POST['woocommerce_meta_nonce']) && \is_string($_POST['woocommerce_meta_nonce'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['woocommerce_meta_nonce']));
        } elseif (isset($_POST['security']) && \is_string($_POST['security'])) {
            $nonce = sanitize_text_field(wp_unslash($_POST['security']));
        }
        return $this->canManageMappings() && $nonce !== '' && current_user_can('edit_product', (int) ($product->get_parent_id() ?: $product->get_id()))
            && (wp_verify_nonce($nonce, 'woocommerce_save_data')
                || wp_verify_nonce($nonce, 'save-variations'));
    }

    private function canManageMappings(): bool
    {
        return current_user_can('vidyalms_admin') || current_user_can('manage_options');
    }

    private function saveMapping(\WC_Product $product, mixed $submitted): void
    {
        if (!\is_scalar($submitted)) {
            return;
        }
        $mappings = (array) get_option(self::MAPPINGS_OPTION, []);
        if ((string) $submitted === '') {
            unset($mappings[$product->get_id()]);
            $product->delete_meta_data(self::COURSE_META);
        } elseif (preg_match('/^[0-9]+$/D', (string) $submitted)
            && ((int) $submitted === 0 || $this->courseExists((int) $submitted))) {
            $mappings[$product->get_id()] = (int) $submitted;
            $product->update_meta_data(self::COURSE_META, (string) absint($submitted));
        } elseif (class_exists('WC_Admin_Meta_Boxes')) {
            \WC_Admin_Meta_Boxes::add_error(__('Select a course marked Paid in VidyaLMS before mapping this product.', 'vidyalms'));
            return;
        } else {
            return;
        }
        // Options are outside Woo product/item metadata and its REST write surface.
        update_option(self::MAPPINGS_OPTION, $mappings, false);
        $this->productCache = [];
    }

    public function snapshotCheckoutItem($item, $cartItemKey, $values, $order): void
    {
        if ($item instanceof \WC_Order_Item_Product) {
            $snapshot = $this->snapshot($item, $order, false);
            if ($snapshot['courses'] !== [] && ((int) $item->get_id() === 0 || (int) $order->get_id() === 0)) {
                // Classic checkout has no persistent IDs yet. Carry only the
                // server-built value until Woo assigns the item its real ID.
                $this->pendingSnapshots[spl_object_id($item)] = $snapshot;
            }
        }
    }

    public function requireAccount($required): bool
    {
        if ((bool) $required) {
            return true;
        }
        $cart = WC()->cart;
        if ($cart !== null) {
            foreach ($cart->get_cart() as $line) {
                if ($this->courseForProduct((int) ($line['product_id'] ?? 0), (int) ($line['variation_id'] ?? 0)) > 0) {
                    return true;
                }
            }
        }
        return false;
    }

    public function validateCheckoutOrder($order): void
    {
        if (!$order instanceof \WC_Order) {
            return;
        }
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $snapshot = $this->snapshot($item, $order, (int) $item->get_id() > 0);
            if ($snapshot['courses'] !== [] && (int) $order->get_customer_id() <= 0) {
                throw new \Exception(esc_html__('Please sign in or create an account to purchase course access.', 'vidyalms'));
            }
        }
    }

    /**
     * Worker entry point: re-read persisted state on retries and stale deliveries.
     * Never register this on a shop lifecycle hook: that hook may run inside a
     * shop transaction on the same connection used by the core transaction.
     * Never complete a payment, change shop status, refund, or identify a learner by email.
     */
    public function syncOrder($orderOrId): void
    {
        global $wpdb;

        $id = $orderOrId instanceof \WC_Order ? (int) $orderOrId->get_id() : (int) $orderOrId;
        if ($id <= 0 || isset($this->syncing[$id]) || !self::available()) {
            return;
        }
        $this->syncing[$id] = true;
        $order = null;
        $lock = null;
        $customerLock = null;
        $lockDatabase = $wpdb;
        $related = [];
        try {
            if (!$lockDatabase instanceof \wpdb) {
                throw new \RuntimeException(esc_html__('The database is unavailable for course access synchronization.', 'vidyalms'));
            }
            // Serialize the shop read AND access write across requests. Core's CAS
            // alone cannot detect a shop snapshot read before a newer sync committed.
            // Scope server-wide lock names to the database/site and stay below 64 bytes.
            $database = \defined('DB_NAME') ? (string) \constant('DB_NAME') : '';
            $name = 'vidyalms_wc_' . substr(hash('sha256', $database . ':' . $lockDatabase->prefix . ':' . $id), 0, 48);
            $acquired = $lockDatabase->get_var($lockDatabase->prepare('SELECT GET_LOCK(%s, 0)', $name));
            if ((string) $acquired !== '1') {
                if ((string) $acquired !== '0') {
                    throw new \RuntimeException(esc_html__('Could not lock this shop order for course access synchronization.', 'vidyalms'));
                }
                $this->schedule($id, 10);
                return;
            }
            $lock = $name;
            $order = wc_get_order($id);
            if ($order === false || $order === null) {
                // An unsuccessful database read is not evidence of deletion.
                if ($lockDatabase->last_error !== '') {
                    throw new \RuntimeException(esc_html__('Could not read the shop order for course access synchronization.', 'vidyalms'));
                }
                $this->revokeTrackedObject($id, 'woocommerce_deleted');
                return;
            }
            if (!$order instanceof \WC_Order || $order->get_type() === 'shop_order_refund') {
                return;
            }
            if (get_option(self::ERASED_OPTION . $id, false)) {
                return;
            }
            $customerId = (int) $order->get_customer_id();
            $customerLock = self::acquireCustomerLock($customerId);
            if ($customerId > 0 && get_option('vidyalms_commerce_erasure_pending_' . $customerId, false)) {
                return;
            }
            if ($order->has_status('trash')) {
                $this->revokeTrackedObject($id, 'woocommerce_trashed');
                return;
            }
            $subscription = $order->get_type() === 'shop_subscription';
            $courses = [];
            $mapped = [];
            $related = !$subscription && function_exists('wcs_get_subscriptions_for_order')
                ? wcs_get_subscriptions_for_order($order, ['order_type' => 'any']) : [];
            foreach ($order->get_items('line_item') as $item) {
                if (!$item instanceof \WC_Order_Item_Product) {
                    continue;
                }
                $snapshot = $this->snapshot($item, $order, true, $related);
                if (!$subscription && $snapshot['recurring']) {
                    continue;
                }
                $mapped = array_merge($mapped, $snapshot['courses']);
                if ($this->remainingItem($order, $item)
                    && (!$subscription || $this->subscriptionItemRetained($order, $item))) {
                    $courses = array_merge($courses, $snapshot['courses']);
                }
            }
            $courses = $this->ids($courses);
            $previous = $this->ids($order->get_meta(self::COURSES_META));
            $scope = $this->ids(array_merge($mapped, $previous));
            $active = $subscription
                ? \in_array($order->get_status(), ['active', 'pending-cancel'], true)
                : $this->paid($order);
            $expires = $subscription ? $this->subscriptionExpiry($order) : null;
            if ($expires !== null && strtotime($expires . ' UTC') <= time()) {
                $active = false;
            }
            // Pending cancellation without its prepaid end must never create lifetime access.
            if ($subscription && $order->get_status() === 'pending-cancel' && $expires === null) {
                $active = false;
            }
            if ($scope !== [] || $order->meta_exists(self::COURSES_META)) {
                // Persist the correlation marker before core writes. If the request dies
                // after core commits, an empty/removed item set still reconciles later.
                $order->update_meta_data(self::COURSES_META, $scope);
                $order->save_meta_data();
                $userId = (int) $order->get_customer_id();
                $deletion = $userId <= 0 ? $this->customerDeletionState($order) : '';
                if ($deletion !== 'settled') {
                    $kernel = $this->kernel();
                    if ($kernel === null) {
                        throw new \RuntimeException(esc_html__('The learning engine is unavailable.', 'vidyalms'));
                    }
                    $actor = $kernel->backgroundActor->resolve($scope[0] ?? null);
                    if (!$actor instanceof Principal) {
                        throw new \RuntimeException(esc_html__('Configure an account with enrolment permission in commerce.background_actor_user_id.', 'vidyalms'));
                    }
                    $kernel->syncShopAccess->execute(
                        $actor, 'woocommerce', $subscription ? 'subscription' : 'order', (string) $id,
                        max(0, $userId), $courses, $active && $userId > 0, $expires,
                        $deletion === 'deleted' ? 'woocommerce_customer_deleted' : 'woocommerce_' . $order->get_status(),
                    );
                }
                if ($active && $userId <= 0 && $courses !== [] && $deletion === '') {
                    throw new \RuntimeException(esc_html__('Assign this course order to a customer account. Access will be retried automatically.', 'vidyalms'));
                }
                $order->update_meta_data(self::COURSES_META, $this->ids($mapped));
                if ($deletion !== '') {
                    $message = __('Course access was removed because the linked customer account was deleted. Assign a customer account to this order to restore access.', 'vidyalms');
                    if ($order->get_meta(self::ERROR_META) !== $message) {
                        $order->update_meta_data(self::ERROR_META, $message);
                        $order->add_order_note($message);
                    }
                    wp_clear_scheduled_hook(self::RETRY_HOOK, [$id]);
                    if (function_exists('as_unschedule_all_actions')) {
                        as_unschedule_all_actions(self::RETRY_HOOK, [$id], self::JOB_GROUP);
                    }
                } else {
                    $order->delete_meta_data(self::ERROR_META);
                }
                $order->save_meta_data();
            }
        } catch (\Throwable $error) {
            $this->log('sync(' . $id . ')', $error);
            $this->schedule($id);
            try {
                if ($order instanceof \WC_Order) {
                    // A repeated failure creates one private note, not one note per delivery.
                    $message = $error->getMessage();
                    if ($order->get_meta(self::ERROR_META) !== $message) {
                        $order->update_meta_data(self::ERROR_META, $message);
                        $order->save_meta_data();
                        /* translators: %s: synchronization error. */
                        $order->add_order_note(sprintf(__('VidyaLMS access sync needs attention: %s', 'vidyalms'), $message));
                    }
                }
            } catch (\Throwable $reportingError) {
                // A broken metadata/note store must not interrupt later worker jobs.
                $this->log('record sync failure(' . $id . ')', $reportingError);
            }
        } finally {
            self::releaseCustomerLock($customerLock);
            if ($lock !== null && $lockDatabase instanceof \wpdb) {
                try {
                    $lockDatabase->get_var($lockDatabase->prepare('SELECT RELEASE_LOCK(%s)', $lock));
                } catch (\Throwable $releaseError) {
                    $this->log('release sync lock(' . $id . ')', $releaseError);
                }
            }
            unset($this->syncing[$id]);
        }
        // Each subscription takes its own lock; never nest cross-object locks.
        foreach ($related as $sub) {
            $this->syncOrder($sub);
        }
    }

    /** Guest checkout remains retryable; a formerly linked, deleted CMS account does not. */
    private function customerDeletionState(\WC_Order $order): string
    {
        $ports = $this->ports();
        if ($ports === null) {
            return '';
        }
        $link = $ports->database->selectOne(Query::from('shop_access_links')
            ->where('shop', '=', 'woocommerce')
            ->where('object_type', '=', $order->get_type() === 'shop_subscription' ? 'subscription' : 'order')
            ->where('object_id', '=', (string) $order->get_id()));
        if (($link['last_reason'] ?? null) === 'woocommerce_customer_deleted') {
            return 'settled';
        }
        $previousUser = (int) ($link['user_id'] ?? 0);
        return $previousUser > 0 && !$ports->users->exists($previousUser) ? 'deleted' : '';
    }

    /** Called under the same per-object lock as ordinary synchronization. */
    private function revokeTrackedObject(int $id, string $reason): void
    {
        $ports = $this->ports();
        if ($ports === null) {
            throw new \RuntimeException(esc_html__('The learning engine is unavailable.', 'vidyalms'));
        }
        // Core correlation survives deletion of Woo's object and item snapshots.
        // Read only LMS links; no assumptions about HPOS or WordPress order tables.
        $links = $ports->database->selectAll(Query::from('shop_access_links')
            ->select(['object_type', 'entitlements'])
            ->where('shop', '=', 'woocommerce')->where('object_id', '=', (string) $id)
            ->whereIn('object_type', ['order', 'subscription'])->limit(2));
        foreach ($links as $link) {
            $grants = json_decode((string) $link['entitlements'], true, 512, JSON_THROW_ON_ERROR);
            if (!\is_array($grants)) {
                throw new \RuntimeException('Invalid shop access correlation.');
            }
            if ($grants === []) {
                continue;
            }
            if ($link['object_type'] === 'subscription' && !class_exists('WC_Subscription')) {
                throw new \RuntimeException(esc_html__('WooCommerce Subscriptions must be active to verify subscription access.', 'vidyalms'));
            }
            $kernel = $this->kernel();
            $courses = $this->ids(array_keys($grants));
            $actor = $kernel?->backgroundActor->resolve($courses[0] ?? null);
            if (!$actor instanceof Principal) {
                throw new \RuntimeException(esc_html__('Configure an account with enrolment permission in commerce.background_actor_user_id.', 'vidyalms'));
            }
            $kernel->syncShopAccess->execute($actor, 'woocommerce', (string) $link['object_type'],
                (string) $id, 0, [], false, null, $reason);
        }
    }

    /** Catalogue edits never rewrite purchases; changing an item's product refreshes its mapping. */
    private function snapshot(\WC_Order_Item_Product $item, \WC_Order $order, bool $save, array $related = []): array
    {
        $signature = (int) $item->get_product_id() . ':' . (int) $item->get_variation_id();
        if (get_option(self::ERASED_OPTION . $order->get_id(), false)) {
            return ['courses' => [], 'recurring' => false, 'product' => $signature];
        }
        $option = self::SNAPSHOT_OPTION . (int) $order->get_id();
        $snapshots = (array) get_option($option, []);
        $existing = $snapshots[(int) $item->get_id()] ?? null;
        if (\is_array($existing) && isset($existing['courses'], $existing['recurring'], $existing['product'])
            && $existing['product'] === $signature) {
            if ($this->ids($existing['courses']) === []) {
                // Retire older empty option entries without retroactively mapping the purchase.
                unset($snapshots[(int) $item->get_id()]);
                $snapshots === [] ? delete_option($option) : $this->saveSnapshots($option, $snapshots);
                return $this->unmappedSnapshot($item, $signature, $save);
            }
            // Relations can be created after the checkout snapshot. Promote ownership,
            // but never turn a former subscription line into a lifetime purchase.
            $recurring = (bool) $existing['recurring'] || $order->get_type() === 'shop_subscription';
            foreach ($related as $sub) {
                foreach ($sub->get_items('line_item') as $subItem) {
                    $recurring = $recurring || $this->sameProduct($item, $subItem);
                }
            }
            $snapshot = ['courses' => $this->ids($existing['courses']), 'recurring' => $recurring, 'product' => $signature];
            if ($snapshot !== $existing) {
                $snapshots[(int) $item->get_id()] = $snapshot;
                $this->saveSnapshots($option, $snapshots);
                $item->update_meta_data(self::ITEM_META, $snapshot);
                if ($save) {
                    $item->save_meta_data();
                }
            }
            return $snapshot;
        }
        // Only a negative item marker is trusted. Positive access always comes
        // from the protected option snapshot or the current approved mapping.
        if ($item->get_meta(self::ITEM_META) === 'unmapped:' . $signature) {
            return ['courses' => [], 'recurring' => false, 'product' => $signature];
        }
        $course = $this->courseForProduct((int) $item->get_product_id(), (int) $item->get_variation_id());
        if ($course < 1) {
            if ($existing !== null) {
                unset($snapshots[(int) $item->get_id()]);
                $snapshots === [] ? delete_option($option) : $this->saveSnapshots($option, $snapshots);
            }
            return $this->unmappedSnapshot($item, $signature, $save);
        }
        $product = $item->get_product();
        $recurring = $order->get_type() === 'shop_subscription'
            || ($product instanceof \WC_Product && ($product->is_type(['subscription', 'subscription_variation', 'variable-subscription'])
                || (class_exists('WC_Subscriptions_Product') && \WC_Subscriptions_Product::is_subscription($product))))
            || $item->meta_exists('_switched_subscription_item_id');
        foreach ($related as $sub) {
            foreach ($sub->get_items('line_item') as $subItem) {
                if ($this->sameProduct($item, $subItem)) {
                    $recurring = true;
                }
            }
        }
        $snapshot = ['courses' => [$course], 'recurring' => $recurring, 'product' => $signature];
        if ((int) $order->get_id() > 0 && (int) $item->get_id() > 0) {
            $snapshots[(int) $item->get_id()] = $snapshot;
            $this->saveSnapshots($option, $snapshots);
        }
        $item->update_meta_data(self::ITEM_META, $snapshot);
        if ($save) {
            $item->save_meta_data();
        }
        return $snapshot;
    }

    /** Keep only the purchase-time refusal, without allocating an order option. */
    private function unmappedSnapshot(\WC_Order_Item_Product $item, string $signature, bool $save): array
    {
        $marker = 'unmapped:' . $signature;
        if ($item->get_meta(self::ITEM_META) !== $marker) {
            $item->update_meta_data(self::ITEM_META, $marker);
            if ($save) {
                $item->save_meta_data();
            }
        }
        return ['courses' => [], 'recurring' => false, 'product' => $signature];
    }

    private function remainingItem(\WC_Order $order, \WC_Order_Item_Product $item): bool
    {
        $quantity = (float) $item->get_quantity();
        if ($quantity <= 0 || abs((float) $order->get_qty_refunded_for_item($item->get_id())) >= $quantity) {
            return false;
        }
        // A merchant can refund a whole line's value without changing its quantity.
        // Compare amounts in Woo's configured minor units; fee-only and partial line refunds retain access.
        $factor = 10 ** (int) wc_get_price_decimals();
        $total = (int) round((float) $item->get_total() * $factor);
        $refunded = (int) round((float) $order->get_total_refunded_for_item($item->get_id()) * $factor);
        return $total <= 0 || $refunded < $total;
    }

    private function saveSnapshots(string $option, array $snapshots): void
    {
        update_option($option, $snapshots, false);
        if (get_option($option, null) !== $snapshots) {
            throw new \RuntimeException('Could not persist the approved course purchase snapshot.');
        }
    }

    private function sameProduct($left, $right): bool
    {
        return $left instanceof \WC_Order_Item_Product && $right instanceof \WC_Order_Item_Product
            && (int) $left->get_product_id() === (int) $right->get_product_id()
            && (int) $left->get_variation_id() === (int) $right->get_variation_id();
    }

    /** Only the current term's order can withdraw current subscription lines. */
    private function subscriptionItemRetained(\WC_Order $subscription, \WC_Order_Item_Product $item): bool
    {
        if (!method_exists($subscription, 'get_last_order')) {
            return true;
        }
        $last = $subscription->get_last_order('all', ['parent', 'renewal', 'switch'],
            ['pending', 'failed', 'on-hold', 'cancelled', 'checkout-draft']);
        if (!$last instanceof \WC_Order) {
            return true; // A manually managed subscription is authoritative in its own right.
        }
        $matched = false;
        foreach ($last->get_items('line_item') as $line) {
            if (!$line instanceof \WC_Order_Item_Product || !$this->sameProduct($item, $line)) {
                continue;
            }
            $matched = true;
            if ($this->paid($last) && $this->remainingItem($last, $line)) {
                return true;
            }
        }
        return !$matched;
    }

    private function subscriptionExpiry(\WC_Order $subscription): ?string
    {
        if (!method_exists($subscription, 'get_time')) {
            return null;
        }
        // next_payment is the paid/trial boundary, end is authoritative for pending-cancel.
        $dates = [(int) $subscription->get_time('end', 'gmt')];
        if ($subscription->get_status() === 'active') {
            $dates[] = (int) $subscription->get_time('next_payment', 'gmt');
        }
        $dates = array_filter($dates, static fn (int $date): bool => $date > 0);
        return $dates === [] ? null : gmdate('Y-m-d H:i:s', min($dates));
    }

    private function courseForProduct(int $productId, int $variationId): int
    {
        $mappings = (array) get_option(self::MAPPINGS_OPTION, []);
        foreach (array_filter([$variationId, $productId]) as $id) {
            // Explicit zero on a variation overrides the parent mapping.
            if (array_key_exists($id, $mappings)) {
                return max(0, (int) $mappings[$id]);
            }
        }
        return 0;
    }

    private function paid(\WC_Order $order): bool
    {
        // COD moves to processing before money is collected. Completion is
        // Woo's merchant confirmation; gateways using payment_complete record
        // a paid date. Other gateways retain Woo's paid-status contract.
        return (bool) $order->is_paid() && ($order->get_payment_method() !== 'cod'
            || get_option('vidyalms_woocommerce_paid_' . $order->get_id(), false) || $order->has_status('completed'));
    }

    public function paymentCompleted($orderId): void
    {
        $id = (int) $orderId;
        if ($id <= 0 || get_option(self::ERASED_OPTION . $id, false)) {
            return;
        }
        $order = wc_get_order($id);
        if (!$order instanceof \WC_Order || $order->get_payment_method() !== 'cod') {
            return;
        }
        $snapshots = (array) get_option(self::SNAPSHOT_OPTION . $id, []);
        foreach ($order->get_items('line_item') as $item) {
            if (!$item instanceof \WC_Order_Item_Product) {
                continue;
            }
            $signature = (int) $item->get_product_id() . ':' . (int) $item->get_variation_id();
            $snapshot = $snapshots[(int) $item->get_id()] ?? null;
            if (is_array($snapshot) && ($snapshot['product'] ?? null) === $signature) {
                $mapped = $this->ids($snapshot['courses'] ?? []) !== [];
            } else {
                $mapped = $item->get_meta(self::ITEM_META) !== 'unmapped:' . $signature
                    && $this->courseForProduct((int) $item->get_product_id(), (int) $item->get_variation_id()) > 0;
            }
            // Record only payment evidence in the shop callback. Snapshot
            // persistence and access projection remain with checkout/worker.
            if ($mapped) {
                update_option('vidyalms_woocommerce_paid_' . $id, true, false);
                return;
            }
        }
    }

    /** Serialize learner erasure with all orders that can recreate that learner's access. */
    public static function acquireCustomerLock(int $userId): ?string
    {
        if ($userId < 1 || !self::available()) {
            return null;
        }
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            throw new \RuntimeException(esc_html__('The database is unavailable for course access synchronization.', 'vidyalms'));
        }
        $database = \defined('DB_NAME') ? (string) \constant('DB_NAME') : '';
        $name = 'vidyalms_customer_' . substr(hash('sha256', $database . ':' . $wpdb->prefix . ':' . $userId), 0, 44);
        // Nonblocking acquisition avoids deadlock with the worker's order lock.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
            throw new \RuntimeException(esc_html__('Course access synchronization is running. Retry erasure or synchronization.', 'vidyalms'));
        }
        return $name;
    }

    public static function releaseCustomerLock(?string $name): void
    {
        if ($name === null) {
            return;
        }
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
    }

    /** Suppress existing purchases, including ones whose first worker has not run. */
    public static function eraseCustomerOrders(int $userId): int
    {
        if ($userId < 1 || !self::available()) {
            return 0;
        }
        global $wpdb;
        $suppressed = 0;
        $types = ['shop_order'];
        $statuses = array_merge(array_keys(wc_get_order_statuses()), ['trash']);
        if (function_exists('wcs_get_subscription_statuses')) {
            $types[] = 'shop_subscription';
            $statuses = array_unique(array_merge($statuses, array_keys(wcs_get_subscription_statuses())));
        }
        for ($page = 1; ; $page++) {
            $ids = wc_get_orders(['customer_id' => $userId, 'type' => $types, 'status' => $statuses,
                'limit' => self::BATCH_SIZE, 'page' => $page, 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids']);
            foreach ($ids as $orderOrId) {
                $id = self::orderId($orderOrId);
                // Share the worker's object lock: a worker already inside its
                // write must finish before we suppress and erase its grant.
                $database = \defined('DB_NAME') ? (string) \constant('DB_NAME') : '';
                $name = 'vidyalms_wc_' . substr(hash('sha256', $database . ':' . $wpdb->prefix . ':' . (int) $id), 0, 48);
                // MySQL advisory locks serialize erasure and order events; WordPress has no lock API and cached results are unsafe.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                if ((string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name)) !== '1') {
                    throw new \RuntimeException('Course access synchronization is running. Retry erasure.');
                }
                try {
                    $order = wc_get_order($id);
                    if ($order instanceof \WC_Order && (int) $order->get_customer_id() !== $userId) {
                        continue; // Reassigned after the customer query; it now belongs to somebody else.
                    }
                    if (!$order instanceof \WC_Order && $wpdb->last_error !== '') {
                        throw new \RuntimeException('Could not verify the customer order during erasure.');
                    }
                    update_option(self::ERASED_OPTION . (int) $id, true, false);
                    if (!get_option(self::ERASED_OPTION . (int) $id, false)) {
                        throw new \RuntimeException('Could not persist the course access erasure marker.');
                    }
                    delete_option(self::SNAPSHOT_OPTION . (int) $id);
                    delete_option('vidyalms_woocommerce_paid_' . (int) $id);
                    if (get_option(self::SNAPSHOT_OPTION . (int) $id, false) !== false
                        || get_option('vidyalms_woocommerce_paid_' . (int) $id, false) !== false) {
                        throw new \RuntimeException('Could not erase the stored course purchase snapshot.');
                    }
                    if ($order instanceof \WC_Order) {
                        foreach ($order->get_items('line_item') as $item) {
                            if ($item instanceof \WC_Order_Item_Product) {
                                $item->delete_meta_data(self::ITEM_META);
                                $item->save_meta_data();
                            }
                        }
                        $order->delete_meta_data(self::COURSES_META);
                        $order->delete_meta_data(self::ERROR_META);
                        $order->save_meta_data();
                    }
                    $suppressed++;
                } finally {
                    // MySQL advisory locks serialize erasure and order events; WordPress has no lock API and cached results are unsafe.
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
                    $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
                }
            }
            if (count($ids) < self::BATCH_SIZE) {
                break;
            }
        }
        return $suppressed;
    }

    /** WordPress invokes this as a separate paginated exporter. */
    public static function exportCustomerAccess($email, $page = 1): array
    {
        $user = \is_string($email) && $email !== '' ? get_user_by('email', $email) : false;
        if (!self::available() || !$user instanceof \WP_User) {
            return ['data' => [], 'done' => true];
        }
        $types = ['shop_order'];
        $statuses = array_merge(array_keys(wc_get_order_statuses()), ['trash']);
        if (function_exists('wcs_get_subscription_statuses')) {
            $types[] = 'shop_subscription';
            $statuses = array_unique(array_merge($statuses, array_keys(wcs_get_subscription_statuses())));
        }
        $ids = wc_get_orders(['customer_id' => (int) $user->ID, 'type' => $types, 'status' => $statuses,
            'limit' => self::BATCH_SIZE, 'page' => max(1, (int) $page), 'orderby' => 'ID', 'order' => 'ASC', 'return' => 'ids']);
        $data = [];
        foreach ($ids as $orderOrId) {
            $id = self::orderId($orderOrId);
            $snapshot = get_option(self::SNAPSHOT_OPTION . (int) $id, []);
            $paid = get_option('vidyalms_woocommerce_paid_' . (int) $id, false);
            $erased = get_option(self::ERASED_OPTION . (int) $id, false);
            if ($snapshot === [] && !$paid && !$erased) {
                continue;
            }
            $data[] = ['group_id' => 'vidyalms-shop-access', 'group_label' => __('VidyaLMS shop access', 'vidyalms'),
                'item_id' => 'vidyalms-shop-order-' . (int) $id, 'data' => [
                    ['name' => __('Order ID', 'vidyalms'), 'value' => (string) $id],
                    ['name' => __('Course mapping at purchase', 'vidyalms'), 'value' => esc_html((string) json_encode($snapshot))],
                    ['name' => __('Payment completion received', 'vidyalms'), 'value' => $paid ? '1' : '0'],
                    ['name' => __('Access erased; old purchase suppressed', 'vidyalms'), 'value' => $erased ? '1' : '0'],
                ]];
        }
        return ['data' => $data, 'done' => count($ids) < self::BATCH_SIZE];
    }

    private static function orderId(mixed $orderOrId): int
    {
        if ($orderOrId instanceof \WC_Order) {
            $orderOrId = $orderOrId->get_id();
        }
        if ((\is_int($orderOrId) || (\is_string($orderOrId) && ctype_digit($orderOrId))) && (int) $orderOrId > 0) {
            return (int) $orderOrId;
        }
        throw new \UnexpectedValueException('WooCommerce returned an invalid order identifier.');
    }

    /** @return list<int> */
    private function ids(mixed $values): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', \is_array($values) ? $values : []), static fn (int $id): bool => $id > 0)));
        sort($ids);
        return $ids;
    }

    public function refundDeleted($refundId, $orderId): void
    {
        $this->queueOrderSync($orderId);
    }

    /** Shop callbacks enqueue only; access writes happen in a separate worker request. */
    public function queueOrderSync($orderOrId): void
    {
        $id = $orderOrId instanceof \WC_Order ? (int) $orderOrId->get_id() : (int) $orderOrId;
        if ($id <= 0 || isset($this->syncing[$id])) {
            return;
        }
        if (function_exists('as_enqueue_async_action') && did_action('action_scheduler_init') > 0) {
            try {
                // A new event must survive an already-running action: uniqueness
                // would discard a change arriving after that worker's shop read.
                if (as_enqueue_async_action(self::RETRY_HOOK, [$id], self::JOB_GROUP, false) > 0) {
                    return;
                }
            } catch (\Throwable $error) {
                $this->log('queue(' . $id . ')', $error);
            }
        }
        $this->schedule($id, 10);
    }

    public function queuePostSync($postId, $post = null): void
    {
        $type = $post instanceof \WP_Post ? $post->post_type : get_post_type((int) $postId);
        if (\in_array($type, ['shop_order', 'shop_subscription'], true)) {
            $this->queueOrderSync($postId);
        }
    }

    public function itemSaved($itemId, $item, $orderId): void
    {
        if ($item instanceof \WC_Order_Item_Product && (int) $itemId > 0 && (int) $orderId > 0
            && isset($this->pendingSnapshots[spl_object_id($item)])) {
            $option = self::SNAPSHOT_OPTION . (int) $orderId;
            $snapshots = (array) get_option($option, []);
            $snapshots[(int) $itemId] = $this->pendingSnapshots[spl_object_id($item)];
            if (!get_option(self::ERASED_OPTION . (int) $orderId, false)) {
                $this->saveSnapshots($option, $snapshots);
            }
            unset($this->pendingSnapshots[spl_object_id($item)]);
        }
        // Defer until the enclosing order/subscription and all of its items are saved.
        $this->queueOrderSync($orderId);
    }

    public function itemDeleting($itemId): void
    {
        $this->queueOrderSync(wc_get_order_id_by_order_item_id((int) $itemId));
    }

    private function schedule(int $id, int $delay = 300): void
    {
        if ($id > 0 && !wp_next_scheduled(self::RETRY_HOOK, [$id])) {
            wp_schedule_single_event(time() + $delay, self::RETRY_HOOK, [$id]);
        }
    }

    /** Periodic repair only visits VidyaLMS correlations, never the entire shop. */
    public function reconcile($page = 1): void
    {
        $this->reconcileTracked();
    }

    /** Keyset scan also reaches deleted/trashed objects omitted by wc_get_orders(). */
    public function reconcileTracked($afterId = 0): void
    {
        $afterId = max(0, (int) $afterId);
        try {
            $ports = $this->ports();
            if ($ports === null) {
                throw new \RuntimeException(esc_html__('The learning engine is unavailable.', 'vidyalms'));
            }
            $links = $ports->database->selectAll(Query::from('shop_access_links')
                ->select(['id', 'object_id'])->where('shop', '=', 'woocommerce')
                ->where('last_reason', '!=', 'privacy_erased')
                ->where('last_reason', '!=', 'woocommerce_customer_deleted')
                ->whereIn('object_type', ['order', 'subscription'])->where('id', '>', $afterId)
                ->orderBy('id')->limit(self::BATCH_SIZE));
            foreach ($links as $link) {
                $this->syncOrder((int) $link['object_id']);
            }
            if (count($links) === self::BATCH_SIZE) {
                $next = (int) $links[array_key_last($links)]['id'];
                if (!wp_next_scheduled(self::TRACKED_BATCH_HOOK, [$next])) {
                    wp_schedule_single_event(time() + 60, self::TRACKED_BATCH_HOOK, [$next]);
                }
            }
        } catch (\Throwable $error) {
            $this->log('reconcile tracked orders', $error);
            if (!wp_next_scheduled(self::TRACKED_BATCH_HOOK, [$afterId])) {
                wp_schedule_single_event(time() + 300, self::TRACKED_BATCH_HOOK, [$afterId]);
            }
        }
    }

    private function log(string $operation, \Throwable $error): void
    {
        try {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error($operation . ': ' . $error->getMessage(), ['source' => 'vidyalms-access']);
            }
        } catch (\Throwable) {
            // Reconciliation remains scheduled even when the shop's log store is unavailable.
        }
    }

    // Catalogue answers

    /**
     * Answers the price question the core's commerce port asks. The first
     * answer wins, so a price another shop already supplied is left alone.
     *
     * @param mixed $price
     * @param mixed $courseId
     *
     * @return mixed
     */
    public function coursePrice($price, $courseId = 0)
    {
        if ($price !== null || !self::available()) {
            return $price;
        }

        $productId = $this->productForCourse((int) $courseId);

        if ($productId === 0) {
            return $price;
        }

        if (!function_exists('wc_get_product') || !function_exists('wc_get_price_to_display') || !function_exists('wc_price')) {
            return $price;
        }

        $product = wc_get_product($productId);

        if (!$product instanceof \WC_Product || !$product->is_purchasable()) {
            return $price;
        }

        $amount   = (float) wc_get_price_to_display($product);
        $decimals = (int) wc_get_price_decimals();
        $minor    = (int) round($amount * (10 ** $decimals));

        $formatted = html_entity_decode(
            wp_strip_all_tags(wc_price($amount)),
            \ENT_QUOTES | \ENT_HTML5,
            'UTF-8'
        );

        return new CoursePrice($minor, (string) get_woocommerce_currency(), $formatted);
    }

    /**
     * @param mixed $url
     * @param mixed $courseId
     *
     * @return mixed
     */
    public function coursePurchaseUrl($url, $courseId = 0)
    {
        if ((\is_string($url) && $url !== '') || !self::available()) {
            return $url;
        }

        $productId = $this->productForCourse((int) $courseId);

        if ($productId === 0) {
            return $url;
        }

        $product = wc_get_product($productId);
        $permalink = $product instanceof \WC_Product ? $product->get_permalink() : '';

        return \is_string($permalink) && $permalink !== '' ? $permalink : $url;
    }

    private function productForCourse(int $courseId): int
    {
        if ($courseId <= 0) {
            return 0;
        }

        if (isset($this->productCache[$courseId])) {
            return $this->productCache[$courseId];
        }

        $mapped = array_keys(array_filter((array) get_option(self::MAPPINGS_OPTION, []),
            static fn ($mappedCourse): bool => (int) $mappedCourse === $courseId));
        if ($mapped === []) {
            return 0;
        }
        $found = get_posts([
            'post_type'        => ['product', 'product_variation'],
            'post_status'      => 'publish',
            'numberposts'      => 1,
            'fields'           => 'ids',
            'post__in'         => array_map('intval', $mapped),
            'orderby'          => 'ID',
            'order'            => 'ASC',
            'no_found_rows'    => true,
            'suppress_filters' => false,
        ]);

        $productId = \is_array($found) && $found !== [] ? (int) $found[0] : 0;

        return $this->productCache[$courseId] = $productId;
    }

    /**
     * @return array<int, string> course id => title
     */
    private function courses(): array
    {
        $ports = $this->ports();

        if ($ports === null) {
            return [];
        }

        try {
            $rows = $ports->database->selectAll(
                Query::from('courses')
                    ->select(['id', 'title'])
                    ->where('price_mode', '=', 'paid')
                    ->orderBy('title')
                    ->limit(self::COURSE_OPTION_LIMIT),
            );
        } catch (\Throwable $error) {
            $this->log('courses()', $error);

            return [];
        }

        $courses = [];

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);

            if ($id > 0) {
                $courses[$id] = (string) ($row['title'] ?? '');
            }
        }

        return $courses;
    }

    private function courseExists(int $courseId): bool
    {
        $ports = $this->ports();

        if ($ports === null) {
            return false;
        }

        try {
            $row = $ports->database->selectOne(Query::from('courses')->select(['id'])->where('id', '=', $courseId)->where('price_mode', '=', 'paid'));
        } catch (\Throwable $error) {
            $this->log('courseExists()', $error);

            return false;
        }

        return $row !== null;
    }

    private function kernel(): ?CoreKernel
    {
        if ($this->kernelResolver !== null) {
            $kernel = ($this->kernelResolver)();

            return $kernel instanceof CoreKernel ? $kernel : null;
        }

        if (function_exists('vidyalms_kernel')) {
            $kernel = vidyalms_kernel();

            return $kernel instanceof CoreKernel ? $kernel : null;
        }

        return null;
    }

    private function ports(): ?Ports
    {
        if ($this->portsResolver !== null) {
            $ports = ($this->portsResolver)();

            return $ports instanceof Ports ? $ports : null;
        }

        if (function_exists('vidyalms_ports')) {
            $ports = vidyalms_ports();

            return $ports instanceof Ports ? $ports : null;
        }

        return null;
    }

}
