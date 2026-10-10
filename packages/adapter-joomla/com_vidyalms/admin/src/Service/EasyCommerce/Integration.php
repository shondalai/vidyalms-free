<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce;

defined('_JEXEC') or die;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory;

final class Integration
{
    public const PLUGIN = 'vidyalms_easycommerce';

    public static function available(): bool
    {
        return ComponentHelper::isEnabled('com_easycommerce') && ComponentHelper::isEnabled('com_vidyalms')
            && PluginHelper::isEnabled('system', self::PLUGIN);
    }

    public static function settings(): MappingSettings
    {
        $container = Factory::getContainer();
        if (!$container->has(MappingSettings::class)) {
            Factory::getApplication()->bootComponent('com_vidyalms');
        }
        return $container->get(MappingSettings::class);
    }

    public static function mapping(): CourseMapping
    {
        return self::settings()->mapping();
    }

    public static function enqueue(string $type, int $id): void
    {
        if (!self::available()) {
            return;
        }
        // No Core call inside a shop transaction. A rollback rolls back this hint;
        // a missed event is repaired by the independently persisted source scan.
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $queue = new SyncQueue($db);
        $queue->enqueue($type, $id, gmdate('Y-m-d H:i:s'));
        if ($type === 'order') {
            $queue->captureOrderMapping($id, new ShopReader($db, self::mapping()));
        }
    }

    /**
     * @param ?list<array{type: string, id: int}> $objects Request targets; null enables the scheduled full scan.
     * @return array{processed: int, failed: int, skipped: bool}
     */
    public static function reconcile(int $limit = 100, ?array $objects = null, ?float $maxSeconds = null): array
    {
        $deadline = $maxSeconds === null ? null : hrtime(true) + max(0, $maxSeconds) * 1_000_000_000;
        $limit = max(1, min(500, $limit));
        $result = ['processed' => 0, 'failed' => 0, 'skipped' => true];
        if (!self::available()) {
            return $result;
        }
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        if ($objects !== null && !PostResponseSync::connectionIdle($db)) {
            return $result;
        }
        $queue = new SyncQueue($db);
        if (!$queue->lock(true)) {
            return $result;
        }
        try {
            $kernel = KernelFactory::kernel();
            self::settings()->reset();
            $sync = new AccessSync(
                static fn (int $course): ?Principal => $kernel->backgroundActor->resolve($course),
                static function (Principal $actor, string $shop, string $type, string $id, int $user, array $courses, bool $active, ?string $expires, string $reason) use ($kernel): void {
                    $kernel->syncShopAccess->execute($actor, $shop, $type, $id, $user, $courses, $active, $expires, $reason);
                },
            );
            $reader = new ShopReader($db, self::mapping());
            $now = gmdate('Y-m-d H:i:s');
            if ($objects === null) {
                $queue->discover($reader, $now);
            } else {
                // Initial order events can precede subscription creation. Resolve
                // those links after commit, so recurring access joins this run.
                $objects = array_slice($objects, 0, $limit);
                foreach ($objects as $object) {
                    if ($deadline !== null && hrtime(true) >= $deadline) {
                        break;
                    }
                    if ($object['type'] === 'order') {
                        foreach ($reader->subscriptionsForOrder($object['id'], $limit) as $subscription) {
                            $queue->enqueue('subscription', $subscription, $now);
                            $objects[] = ['type' => 'subscription', 'id' => $subscription];
                        }
                    }
                }
            }
            $result['skipped'] = false;
            foreach ($queue->due($now, $limit, $objects) as $job) {
                // Finish an in-flight Core transaction; check the budget between
                // records, never interrupt a grant or acknowledgement halfway.
                if ($deadline !== null && hrtime(true) >= $deadline) {
                    break;
                }
                try {
                    $type = (string) $job['object_type'];
                    $id = (int) $job['object_id'];
                    if ($type === 'order' && $objects === null) {
                        foreach ($reader->subscriptionsForOrder($id) as $subscription) {
                            $queue->enqueue('subscription', $subscription, $now);
                        }
                    }
                    $previousSnapshot = $job['mapping_snapshot'] === null ? null : json_decode($job['mapping_snapshot'], true, 512, JSON_THROW_ON_ERROR);
                    $snapshot = $reader->snapshot($type, $id, $previousSnapshot);
                    if ($snapshot !== null && $snapshot !== $previousSnapshot) {
                        $snapshot = $queue->rememberMapping($job, $snapshot, $type === 'order');
                    }
                    $state = $reader->state($type, $id, $now, $snapshot);
                    $sync->execute($type, $id, $state, (int) $job['user_id'],
                        [...json_decode($job['course_ids'], true, 512, JSON_THROW_ON_ERROR), ...$reader->linkedCourses($type, $id)],
                        static fn (int $user, array $courses) => $queue->remember($job, $user, $courses));
                    $queue->finish($job, $now, retain: $state->courses !== [] || $reader->isRelevant($type, $id));
                    $result['processed']++;
                } catch (\Throwable $failure) {
                    $queue->finish($job, $now, $failure);
                    self::log($failure, (string) $job['object_type'] . ':' . $job['object_id']);
                    $result['failed']++;
                }
            }
        } finally {
            $queue->lock(false);
        }
        return $result;
    }

    public static function log(\Throwable $failure, string $context): void
    {
        Log::add('EasyCommerce access sync [' . $context . ']: ' . get_class($failure)
            . ($failure->getMessage() === 'PLG_SYSTEM_VIDYALMS_EASYCOMMERCE_REFUND_REVIEW' ? ' (refund review required: identify returned course quantities in the shop; existing access is unchanged)' : '')
            . ($failure->getMessage() === 'PLG_SYSTEM_VIDYALMS_EASYCOMMERCE_ACTOR_REQUIRED' ? ' (configure an actor allowed to enrol on every mapped course)' : ''),
            Log::WARNING, 'com_vidyalms');
    }
}
