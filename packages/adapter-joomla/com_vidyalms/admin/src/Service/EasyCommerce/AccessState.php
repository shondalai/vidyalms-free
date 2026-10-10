<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce;

defined('_JEXEC') or die;

/** Only shop access facts cross this boundary. No prices, refunds or payments are calculated. */
final class AccessState
{
    /** @param list<int> $courses */
    public function __construct(
        public readonly int $userId,
        public readonly array $courses,
        public readonly bool $active,
        public readonly ?string $expiresAt = null,
        public readonly string $reason = 'easycommerce_sync',
        public readonly bool $needsReview = false,
    ) {
    }

    public static function order(?array $order, array $items, CourseMapping $mapping): self
    {
        $courses = [];
        foreach ($items as $item) {
            if (self::recurring($item) || !self::remaining($item)) {
                continue;
            }
            $courses = [...$courses, ...$mapping->courses((int) $item['product_id'], (int) ($item['variation_id'] ?? 0))];
        }
        return new self(
            (int) ($order['user_id'] ?? 0), CourseMapping::ids($courses),
            self::paid($order), reason: 'easycommerce_order_sync',
            needsReview: self::refundNeedsReview($order, $items, $mapping),
        );
    }

    public static function subscription(?array $subscription, ?array $paidOrder, array $items, CourseMapping $mapping, string $now): self
    {
        $userId = (int) ($subscription['user_id'] ?? 0);
        $productId = (int) ($subscription['product_id'] ?? 0);
        $variationId = (int) ($subscription['variation_id'] ?? 0);
        $courses = $mapping->courses($productId, $variationId);
        $status = (string) ($subscription['status'] ?? 'missing');
        $dates = [];
        foreach (['next_payment_date', 'end_date'] as $key) {
            $date = self::date($subscription[$key] ?? null);
            if ($date !== null) {
                $dates[] = $date;
            }
        }
        sort($dates, SORT_STRING);
        $expires = $dates[0] ?? null;
        $remaining = false;
        foreach ($items as $item) {
            // A plan change may legitimately change the product since the paid order.
            // Exact product/variation matching would deny the new plan. The reader
            // supplies only this subscription's recurring lines from its paid order.
            $remaining = $remaining || self::remaining($item);
        }
        $active = in_array($status, ['active', 'pending-cancel', 'pending-pause'], true)
            && $userId > 0 && (int) ($paidOrder['user_id'] ?? 0) === $userId
            && self::paid($paidOrder) && $remaining && $expires !== null && $expires > $now;
        return new self($userId, $courses, $active, $expires, 'easycommerce_subscription_' . $status,
            $active && self::refundNeedsReview($paidOrder, $items, null));
    }

    /** Amounts cannot identify which course was returned, including fee-only refunds. */
    private static function refundNeedsReview(?array $order, array $items, ?CourseMapping $mapping): bool
    {
        if (!self::paid($order) || ($order['payment_status'] ?? '') !== 'partially-refunded') {
            return false;
        }
        foreach ($items as $item) {
            if ($mapping !== null && $mapping->courses((int) $item['product_id'], (int) ($item['variation_id'] ?? 0)) === []) {
                continue;
            }
            $metadata = self::metadata($item['metadata'] ?? []);
            if (!isset($item['refunded_quantity']) && !isset($metadata['refunded_quantity'])) {
                return true;
            }
        }
        return false;
    }

    public static function paid(?array $order): bool
    {
        return $order !== null && (int) ($order['user_id'] ?? 0) > 0
            && in_array($order['payment_status'] ?? '', ['paid', 'partially-refunded'], true)
            && ($order['allocation_status'] ?? 'committed') === 'committed'
            && !in_array($order['status'] ?? '', ['cancelled', 'refunded', 'failed', 'voided', 'trash'], true);
    }

    public static function recurring(array $item): bool
    {
        $metadata = self::metadata($item['metadata'] ?? []);
        return !empty($item['is_subscription']) || !empty($item['subscription_id'])
            || !empty($metadata['is_subscription']) || !empty($metadata['subscription_data'])
            || in_array($metadata['product_type'] ?? $item['product_type'] ?? '', ['subscription', 'variable_subscription'], true);
    }

    public static function remaining(array $item): bool
    {
        $metadata = self::metadata($item['metadata'] ?? []);
        // EasyCommerce currently reports amount-only refunds. Explicit cumulative
        // line quantities (when supplied by the shop) take precedence; never infer
        // a quantity by dividing a refund amount by a price.
        $refunded = max(0, (int) ($item['refunded_quantity'] ?? $metadata['refunded_quantity'] ?? 0));
        return (int) ($item['quantity'] ?? 0) > $refunded;
    }

    public static function metadata(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            $value = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        }
        if ($value === '') {
            return [];
        }
        return (array) $value;
    }

    private static function date(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '0000-')) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $value : null;
    }
}
