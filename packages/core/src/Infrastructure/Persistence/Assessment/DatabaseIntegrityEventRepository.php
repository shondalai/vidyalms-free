<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\IntegrityEvent;
use Shondalai\VidyaLMS\Core\Domain\Assessment\IntegrityEventRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The advisory integrity ledger against the database port. Insert and read,
 * and deliberately nothing else.
 */
final class DatabaseIntegrityEventRepository implements IntegrityEventRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function add(IntegrityEvent $event): IntegrityEvent
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $id = $this->database->insert('integrity_events', [
            'attempt_id' => $event->attemptId,
            'kind' => $event->kind,
            'actor' => $event->actor,
            'occurred_at' => $event->occurredAt->format('Y-m-d H:i:s'),
            'context' => $event->context === null ? null : json_encode($event->context, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $event->withId($id);
    }

    public function forAttempt(int $attemptId, int $limit, int $offset): array
    {
        $rows = $this->database->selectAll(
            Query::from('integrity_events')
                ->where('attempt_id', '=', $attemptId)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit($limit)
                ->offset($offset),
        );

        return array_map($this->fromRow(...), $rows);
    }

    public function countForAttempt(int $attemptId): int
    {
        return $this->database->count(
            Query::from('integrity_events')->where('attempt_id', '=', $attemptId),
        );
    }

    /**
     * @return array<string, int>
     */
    public function countsForAttempt(int $attemptId): array
    {
        $rows = $this->database->selectAll(
            Query::from('integrity_events')
                ->select(['kind'])
                ->where('attempt_id', '=', $attemptId),
        );

        $counts = [];

        foreach ($rows as $row) {
            $kind = (string) $row['kind'];
            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): IntegrityEvent
    {
        $context = $row['context'] ?? null;
        $decoded = \is_string($context) && $context !== '' ? json_decode($context, true) : null;
        $object = null;

        if (\is_array($decoded)) {
            $object = [];

            /** @var mixed $item */
            foreach ($decoded as $name => $item) {
                $object[(string) $name] = $item;
            }
        }

        return new IntegrityEvent(
            (int) $row['id'],
            (int) $row['attempt_id'],
            (string) $row['kind'],
            new \DateTimeImmutable((string) $row['occurred_at'], new \DateTimeZone('UTC')),
            $object,
            (string) ($row['actor'] ?? IntegrityEvent::ACTOR_SYSTEM),
        );
    }
}
