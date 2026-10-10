<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

/** Durable, coalescing notifications plus a rotating full-source reconciliation cursor. */
final class SyncQueue
{
    public const TABLE = '#__vidyalms_easycommerce_sync';
    private const CURSORS = '#__vidyalms_easycommerce_cursors';

    public function __construct(private readonly DatabaseInterface $db)
    {
    }

    public function enqueue(string $type, int $id, string $now): void
    {
        if (!in_array($type, ['order', 'subscription'], true) || $id < 1) {
            return;
        }
        $update = $this->db->createQuery()->update($this->db->quoteName(self::TABLE))
            ->set('revision = revision + 1')->set('next_attempt_at = :now')->set('checked_at = NULL')
            ->where('object_type = :type')->where('object_id = :id')
            ->bind(':type', $type)->bind(':id', $id, ParameterType::INTEGER)->bind(':now', $now);
        $this->db->setQuery($update)->execute();
        if ($this->db->getAffectedRows() > 0) {
            return;
        }
        $query = $this->db->createQuery()->insert($this->db->quoteName(self::TABLE))
            ->columns($this->db->quoteName(['object_type', 'object_id', 'revision', 'course_ids', 'next_attempt_at']))
            ->values(':type, :id, 1, :courses, :now')
            ->bind(':type', $type)->bind(':id', $id, ParameterType::INTEGER)->bind(':now', $now);
        $courses = '[]';
        $query->bind(':courses', $courses);
        try {
            $this->db->setQuery($query)->execute();
        } catch (\Joomla\Database\Exception\ExecutionFailureException $failure) {
            // A concurrent first notification may have inserted this unique key.
            // Only recover if updating that exact key now succeeds.
            $this->db->setQuery($update)->execute();
            if ($this->db->getAffectedRows() < 1) {
                throw $failure;
            }
        }
    }

    public function discover(ShopReader $reader, string $now, int $limit = 25): void
    {
        foreach (['order', 'subscription'] as $type) {
            $query = $this->db->createQuery()->select($this->db->quoteName('last_id'))
                ->from($this->db->quoteName(self::CURSORS))->where($this->db->quoteName('object_type') . ' = :type')->bind(':type', $type);
            $cursor = (int) $this->db->setQuery($query)->loadResult();
            $ids = $reader->scan($type, $cursor, $limit);
            foreach ($ids as $id) {
                // Existing records are already periodically revisited, including
                // deleted shop objects. Discovery must not reset retry backoff.
                $lookup = $this->db->createQuery()->select($this->db->quoteName('id'))->from($this->db->quoteName(self::TABLE))
                    ->where('object_type = :type')->where('object_id = :id')
                    ->bind(':type', $type)->bind(':id', $id, ParameterType::INTEGER);
                if (!$this->db->setQuery($lookup)->loadResult() && $reader->isRelevant($type, $id)) {
                    $this->enqueue($type, $id, $now);
                }
            }
            $last = count($ids) < $limit ? 0 : (int) end($ids);
            $update = $this->db->createQuery()->update($this->db->quoteName(self::CURSORS))
                ->set('last_id = :last')->where('object_type = :type')->bind(':last', $last, ParameterType::INTEGER)->bind(':type', $type);
            $this->db->setQuery($update)->execute();
        }
    }

    /** Capture the purchase mapping in the shop transaction, before later catalogue edits. */
    public function captureOrderMapping(int $orderId, ShopReader $reader): void
    {
        $query = $this->db->createQuery()->select('*')->from($this->db->quoteName(self::TABLE))
            ->where("object_type = 'order'")->where('object_id = :id')->bind(':id', $orderId, ParameterType::INTEGER);
        $job = $this->db->setQuery($query)->loadAssoc();
        if ($job && $job['mapping_snapshot'] === null) {
            $snapshot = $reader->snapshot('order', $orderId, null);
            if ($snapshot !== null) {
                $this->rememberMapping($job, $snapshot, true);
            }
        }
    }

    public function rememberMapping(array $job, array $snapshot, bool $onlyIfEmpty = false): array
    {
        $id = (int) $job['id'];
        $json = json_encode($snapshot, JSON_THROW_ON_ERROR);
        $query = $this->db->createQuery()->update($this->db->quoteName(self::TABLE))
            ->set('mapping_snapshot = :snapshot')->where('id = :id')
            ->bind(':snapshot', $json)->bind(':id', $id, ParameterType::INTEGER);
        if ($onlyIfEmpty) {
            $query->where('mapping_snapshot IS NULL');
        }
        $this->db->setQuery($query)->execute();
        $query = $this->db->createQuery()->select('mapping_snapshot')->from($this->db->quoteName(self::TABLE))
            ->where('id = :id')->bind(':id', $id, ParameterType::INTEGER);
        return json_decode($this->db->setQuery($query)->loadResult(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param ?list<array{type: string, id: int}> $objects */
    public function due(string $now, int $limit, ?array $objects = null): array
    {
        if ($objects === []) {
            return [];
        }
        $query = $this->db->createQuery()->select('*')->from($this->db->quoteName(self::TABLE))
            ->where('next_attempt_at <= :now')->bind(':now', $now)
            ->order('CASE WHEN checked_at IS NULL THEN 0 ELSE 1 END ASC')
            ->order('next_attempt_at ASC')->order('id ASC');
        if ($objects !== null) {
            $clauses = $types = $ids = [];
            foreach (array_values($objects) as $index => $object) {
                $types[$index] = $object['type'];
                $ids[$index] = $object['id'];
                $clauses[] = '(object_type = :type' . $index . ' AND object_id = :object' . $index . ')';
                $query->bind(':type' . $index, $types[$index])->bind(':object' . $index, $ids[$index], ParameterType::INTEGER);
            }
            $query->where('(' . implode(' OR ', $clauses) . ')');
        }
        return $this->db->setQuery($query, 0, $limit)->loadAssocList() ?: [];
    }

    /** Persist the intended subject before calling Core, so a crash can still revoke it later. */
    public function remember(array $job, int $userId, array $courses): void
    {
        $id = (int) $job['id'];
        $json = json_encode(CourseMapping::ids($courses), JSON_THROW_ON_ERROR);
        $query = $this->db->createQuery()->update($this->db->quoteName(self::TABLE))
            ->set('user_id = :user')->set('course_ids = :courses')->where('id = :id')
            ->bind(':user', $userId, ParameterType::INTEGER)->bind(':courses', $json)->bind(':id', $id, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    public function finish(array $job, string $now, ?\Throwable $failure = null, bool $retain = true): void
    {
        $id = (int) $job['id'];
        $revision = (int) $job['revision'];
        if ($failure === null && !$retain) {
            $query = $this->db->createQuery()->delete($this->db->quoteName(self::TABLE))
                ->where('id = :id')->where('revision = :revision')
                ->bind(':id', $id, ParameterType::INTEGER)->bind(':revision', $revision, ParameterType::INTEGER);
            $this->db->setQuery($query)->execute();
            return;
        }
        $attempts = $failure === null ? 0 : (int) $job['attempts'] + 1;
        $delay = $failure === null ? 3600 : min(3600, 60 * (2 ** min(6, $attempts - 1)));
        $next = (new \DateTimeImmutable($now, new \DateTimeZone('UTC')))->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s');
        // Log a stable classification only; provider payloads and secrets never enter this table.
        $error = $failure === null ? '' : get_class($failure) . ': access synchronization failed; see Joomla log';
        if ($failure?->getMessage() === 'PLG_SYSTEM_VIDYALMS_EASYCOMMERCE_REFUND_REVIEW') {
            $error = 'Refund review required: the shop did not identify returned course quantities. Existing access is unchanged.';
        }
        $query = $this->db->createQuery()->update($this->db->quoteName(self::TABLE))
            ->set('attempts = :attempts')->set('last_error = :error')->set('checked_at = :now')
            ->set('next_attempt_at = :next')
            ->where('id = :id')->where('revision = :revision')
            ->bind(':attempts', $attempts, ParameterType::INTEGER)->bind(':error', $error)
            ->bind(':now', $now)->bind(':revision', $revision, ParameterType::INTEGER)->bind(':next', $next)
            ->bind(':id', $id, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    /** A connection-owned lock prevents overlapping workers, without expiring a live worker's lease. */
    public function lock(bool $acquire): bool
    {
        $name = 'vlms-ec-' . sha1($this->db->replacePrefix(self::TABLE));
        $query = $this->db->createQuery()->select($acquire ? 'GET_LOCK(:lock, 0)' : 'RELEASE_LOCK(:lock)')->bind(':lock', $name);
        return (int) $this->db->setQuery($query)->loadResult() === 1;
    }
}
