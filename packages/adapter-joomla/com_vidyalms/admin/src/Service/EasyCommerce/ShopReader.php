<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/** Read-only access to EasyCommerce's persisted orders and subscription periods. */
final class ShopReader
{
    public function __construct(private readonly DatabaseInterface $db, private readonly CourseMapping $mapping)
    {
    }

    public function state(string $type, int $id, string $now, ?array $snapshot = null): AccessState
    {
        $mapping = $snapshot === null ? $this->mapping : new CourseMapping($snapshot['mappings']);
        if ($type === 'order') {
            $order = $this->row('orders', $id);
            $items = $this->items($id);
            if ($snapshot !== null) {
                foreach ($items as &$item) {
                    if (!empty($snapshot['recurring'][$this->planIdentity($item)])) {
                        $item['is_subscription'] = true;
                    }
                }
                unset($item);
            }
            // Renewal/plan-change orders must never create a permanent order grant.
            if (!empty($order['is_renewal']) || !empty($order['plan_change_id'])) {
                $items = [];
            }
            return AccessState::order($order, $items, $mapping);
        }
        if ($type !== 'subscription') {
            throw new \InvalidArgumentException('Unknown EasyCommerce object type.');
        }
        $subscription = $this->row('subscriptions', $id);
        if ($subscription !== null && $snapshot !== null && $snapshot['identity'] !== $this->planIdentity($subscription)) {
            throw new \RuntimeException('Subscription plan changed while reading; retry current shop state.');
        }
        $initialOrder = (int) ($subscription['order_id'] ?? 0);
        // Include refunds/cancellations of a previously paid period; selecting only
        // currently paid orders would fall back to an older paid period after a refund.
        $query = $this->db->createQuery()->select('*')->from($this->db->quoteName('#__easycommerce_orders'))
            ->where('(' . $this->db->quoteName('subscription_id') . ' = :subscription OR ' . $this->db->quoteName('id') . ' = :initial)')
            ->where('(' . $this->db->quoteName('plan_change_id') . ' IS NULL OR ' . $this->db->quoteName('plan_change_id') . ' = 0)')
            ->where('(' . $this->db->quoteName('paid_date') . ' IS NOT NULL OR ' . $this->db->quoteName('payment_status')
                . " IN ('paid', 'partially-refunded', 'refunded'))")
            ->order($this->db->quoteName('paid_date') . ' DESC')->order($this->db->quoteName('id') . ' DESC')
            ->bind(':subscription', $id, ParameterType::INTEGER)->bind(':initial', $initialOrder, ParameterType::INTEGER);
        $order = $this->db->setQuery($query, 0, 1)->loadAssoc();
        $items = $order ? $this->items((int) $order['id']) : [];
        $purchasedProduct = (int) ($subscription['product_id'] ?? 0);
        $purchasedVariation = (int) ($subscription['variation_id'] ?? 0);
        if ($order && (int) $order['id'] === $initialOrder) {
            // The oldest applied change records the original purchased product.
            // Scheduled/unpaid changes do not alter access or this correlation.
            $query = $this->db->createQuery()->select($this->db->quoteName(['from_product_id', 'from_variation_id']))
                ->from($this->db->quoteName('#__easycommerce_subscription_changes'))
                ->where('subscription_id = :id')->where("status = 'applied'")
                ->bind(':id', $id, ParameterType::INTEGER)->order('id ASC');
            $original = $this->db->setQuery($query, 0, 1)->loadAssoc();
            if ($original) {
                $purchasedProduct = (int) $original['from_product_id'];
                $purchasedVariation = (int) ($original['from_variation_id'] ?? 0);
            }
        }
        $items = array_values(array_filter($items, static function (array $item) use ($order, $id, $purchasedProduct, $purchasedVariation): bool {
            // EasyCommerce ties renewal orders to one subscription. For an initial
            // mixed cart, match the purchased subscription product and variation.
            return (!empty($order['is_renewal']) && (int) ($order['subscription_id'] ?? 0) === $id)
                || ((int) $item['product_id'] === $purchasedProduct
                    && (int) ($item['variation_id'] ?? 0) === $purchasedVariation);
        }));
        return AccessState::subscription($subscription, $order ?: null, $items, $mapping, $now);
    }

    /** Preserve purchase mappings; only an actual subscription plan change adopts a new mapping. */
    public function snapshot(string $type, int $id, ?array $previous): ?array
    {
        // Installation enables reconciliation before the administrator has mapped
        // courses. Do not freeze empty purchase mappings during that setup window.
        if ($previous === null && $this->mapping->courseIds() === []) {
            return null;
        }
        if ($type === 'order') {
            if ($previous !== null || $this->row('orders', $id) === null) {
                return $previous;
            }
            $rows = $this->items($id);
            $identity = 'order';
        } else {
            $subscription = $this->row('subscriptions', $id);
            if ($subscription === null) {
                return $previous;
            }
            $identity = $this->planIdentity($subscription);
            if (($previous['identity'] ?? null) === $identity) {
                return $previous;
            }
            $rows = [$subscription];
        }
        $mappings = [];
        $recurring = [];
        foreach ($rows as $row) {
            $product = (int) $row['product_id'];
            $variation = (int) ($row['variation_id'] ?? 0);
            $mappings[] = ['product_id' => $product, 'variation_id' => $variation, 'courses' => $this->mapping->courses($product, $variation)];
            $recurring[$this->planIdentity($row)] = AccessState::recurring($row);
        }
        return ['identity' => $identity, 'mappings' => $mappings, 'recurring' => $recurring];
    }

    private function planIdentity(array $subscription): string
    {
        return (int) $subscription['product_id'] . ':' . (int) ($subscription['variation_id'] ?? 0);
    }

    /** @return list<int> */
    public function subscriptionsForOrder(int $id, int $limit = 0): array
    {
        $query = $this->db->createQuery()->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__easycommerce_subscriptions'))
            ->where($this->db->quoteName('order_id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        $ids = $this->db->setQuery($query, 0, $limit)->loadColumn();
        $order = $this->row('orders', $id);
        $ids[] = (int) ($order['subscription_id'] ?? 0);
        return CourseMapping::ids($ids);
    }

    /** Recover authorization scopes even if the plugin queue was reinstalled. @return list<int> */
    public function linkedCourses(string $type, int $id): array
    {
        $objectId = (string) $id;
        $query = $this->db->createQuery()->select($this->db->quoteName('entitlements'))
            ->from($this->db->quoteName('#__vidyalms_shop_access_links'))
            ->where("shop = 'easycommerce'")->where('object_type = :type')->where('object_id = :id')
            ->bind(':type', $type)->bind(':id', $objectId);
        $raw = $this->db->setQuery($query)->loadResult();
        $ids = $raw ? CourseMapping::ids(json_decode($raw, true, 512, JSON_THROW_ON_ERROR)) : [];
        if ($ids === []) {
            return [];
        }
        $query = $this->db->createQuery()->select($this->db->quoteName('course_id'))
            ->from($this->db->quoteName('#__vidyalms_entitlements'))->whereIn($this->db->quoteName('id'), $ids);
        return CourseMapping::ids($this->db->setQuery($query)->loadColumn());
    }

    /** @return list<int> */
    public function scan(string $type, int $after, int $limit): array
    {
        $table = $type === 'order' ? 'orders' : 'subscriptions';
        $query = $this->db->createQuery()->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__easycommerce_' . $table))
            ->where($this->db->quoteName('id') . ' > :after')->bind(':after', $after, ParameterType::INTEGER)
            ->order($this->db->quoteName('id') . ' ASC');
        return array_map('intval', $this->db->setQuery($query, 0, $limit)->loadColumn());
    }

    /** Discovery ignores shop objects that have never carried a course mapping. */
    public function isRelevant(string $type, int $id): bool
    {
        if ($this->linkedCourses($type, $id) !== []) {
            return true;
        }
        $snapshot = $this->snapshot($type, $id, null);
        foreach ($snapshot['mappings'] ?? [] as $mapping) {
            if ($mapping['courses'] !== []) {
                return true;
            }
        }
        return false;
    }

    private function row(string $table, int $id): ?array
    {
        $query = $this->db->createQuery()->select('*')->from($this->db->quoteName('#__easycommerce_' . $table))
            ->where($this->db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        return $this->db->setQuery($query)->loadAssoc() ?: null;
    }

    private function items(int $order): array
    {
        $query = $this->db->createQuery()->select('i.*')->select($this->db->quoteName('p.type', 'product_type'))
            ->from($this->db->quoteName('#__easycommerce_order_items', 'i'))
            ->leftJoin($this->db->quoteName('#__easycommerce_products', 'p') . ' ON p.id = i.product_id')
            ->where('i.order_id = :order')->bind(':order', $order, ParameterType::INTEGER);
        return $this->db->setQuery($query)->loadAssocList() ?: [];
    }
}
