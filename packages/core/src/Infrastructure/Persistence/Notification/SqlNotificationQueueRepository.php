<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification;

use Shondalai\VidyaLMS\Core\Domain\Notification\QueuedNotification;
use Shondalai\VidyaLMS\Core\Domain\Notification\QueueState;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Notification\NotificationClass;

/**
 * The outbox table.
 *
 * The insert is idempotent by the dedupe digest and the unique index on it is
 * the authority, not the read. The read before the insert is only the cheap
 * path for the ordinary replay; the insert is still attempted inside a try,
 * and a driver that refuses it for the unique index is answered by looking
 * the digest up again and returning null. DatabasePort declares no typed
 * duplicate-key failure and the three shipped drivers signal it three
 * different ways, so the answer is "did the row appear" rather than "which
 * exception was it". Two concurrent writers therefore write one row and one
 * of them answers null.
 *
 * The claim is one conditional statement, and both of the two claims are
 * conditional on something the statement itself changes. Both hosts are
 * documented multi-firers, so two runners over one row is the normal case
 * rather than the exotic one, and a dedupe digest deduplicates rows and not
 * sends.
 */
final class SqlNotificationQueueRepository
{
    public const TABLE = 'notification_queue';

    /** How long a claim holds before another drain may take the row. */
    public const LEASE_SECONDS = 300;

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * Writes the row, or answers null when its digest is already taken.
     *
     * Null is the normal replay answer and not an error: the occurrence has
     * already been enqueued, and a terminal row keeps its digest forever so
     * the occurrence is never re-enqueued behind an operator's back.
     */
    public function enqueue(QueuedNotification $row): ?int
    {
        $existing = $this->database->selectOne(
            Query::from(self::TABLE)->select(['id'])->where('dedupe_key', '=', $row->dedupeKey),
        );

        if ($existing !== null) {
            return null;
        }

        try {
            return $this->insertRow($row);
        } catch (\Throwable $failure) {
            $raced = $this->database->selectOne(
                Query::from(self::TABLE)->select(['id'])->where('dedupe_key', '=', $row->dedupeKey),
            );

            if ($raced === null) {
                throw $failure;
            }

            return null;
        }
    }

    private function insertRow(QueuedNotification $row): int
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        return $this->database->insert(self::TABLE, [
            'rule_id' => $row->ruleId,
            'trigger_key' => $row->triggerKey,
            'category_key' => $row->categoryKey,
            'class' => $row->class->value,
            'subject_type' => $row->subjectType,
            'subject_id' => $row->subjectId,
            'recipient_user_id' => $row->recipientUserId,
            'channel_key' => $row->channelKey,
            'template_key' => $row->templateKey,
            'parameters' => $row->parameters === [] ? null : json_encode($row->parameters, \JSON_THROW_ON_ERROR),
            'event_ref' => $row->eventReference,
            'occurrence_key' => $row->occurrenceKey,
            'dedupe_key' => $row->dedupeKey,
            'run_at' => $row->runAt->format(self::DATETIME_FORMAT),
            'recipient_timezone' => $row->recipientTimezone,
            'recipient_locale' => $row->recipientLocale,
            'state' => $row->state->value,
            'attempts' => $row->attempts,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function find(int $id): ?QueuedNotification
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('id', '=', $id));

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByDedupeKey(string $dedupeKey): ?QueuedNotification
    {
        $row = $this->database->selectOne(Query::from(self::TABLE)->where('dedupe_key', '=', $dedupeKey));

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * The intent rows this fan-out runner has claimed, and nothing else
     * touches.
     *
     * The claim is conditional exactly as the delivery claim is: two runners
     * over one intent row is the normal case on both hosts, and an unclaimed
     * select means both resolve the same audience and race every per-recipient
     * insert. The bin narrows the run the same way the sweeps are sharded, so
     * one run does bounded work.
     *
     * @return list<QueuedNotification>
     */
    public function claimIntents(string $claimToken, int $limit, int $binIndex = 0, int $binCount = 1): array
    {
        $binCount = max(1, $binCount);
        $binIndex = max(0, $binIndex) % $binCount;

        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->select(['id'])
                ->where('state', '=', QueueState::PendingFanout->value)
                ->where('run_at', '<=', $this->clock->now()->format(self::DATETIME_FORMAT))
                ->orderBy('id')
                ->limit($limit * $binCount),
        );

        $now = $this->clock->now();
        $lease = $now->modify('+' . self::LEASE_SECONDS . ' seconds');
        $claimed = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            if ($binCount > 1 && $id % $binCount !== $binIndex) {
                continue;
            }

            if (\count($claimed) >= $limit) {
                break;
            }

            $affected = $this->database->update(self::TABLE, [
                'state' => QueueState::FanningOut->value,
                'claim_token' => $claimToken,
                'claimed_at' => $now->format(self::DATETIME_FORMAT),
                'lease_expires_at' => $lease->format(self::DATETIME_FORMAT),
                'updated_at' => $now->format(self::DATETIME_FORMAT),
            ], ['id' => $id, 'state' => QueueState::PendingFanout->value]);

            if ($affected !== 1) {
                continue;
            }

            $intent = $this->find($id);

            if ($intent !== null) {
                $claimed[] = $intent;
            }
        }

        return $claimed;
    }

    /**
     * Returns expired-lease rows to the state they were claimed from,
     * conditionally on the claim token the dead runner left behind.
     *
     * The recovery claim cannot be "sending to sending": the WHERE predicate
     * would be satisfied by the very value the UPDATE writes, so every runner
     * that saw the expired row would claim it and every one of them would
     * send. Moving the row back on a compare-and-swap over the old token
     * makes the ordinary claim the only claim there is.
     */
    public function reclaimExpiredLeases(int $limit): int
    {
        $now = $this->clock->now();
        $nowString = $now->format(self::DATETIME_FORMAT);
        $reclaimed = 0;

        foreach ([QueueState::Sending, QueueState::FanningOut] as $state) {
            $rows = $this->database->selectAll(
                Query::from(self::TABLE)
                    ->select(['id', 'claim_token'])
                    ->where('state', '=', $state->value)
                    ->where('lease_expires_at', '<', $nowString)
                    ->orderBy('lease_expires_at')
                    ->limit($limit),
            );

            $target = $state->releasesTo();

            if ($target === null) {
                continue;
            }

            foreach ($rows as $row) {
                $affected = $this->database->update(self::TABLE, [
                    'state' => $target->value,
                    'claim_token' => null,
                    'lease_expires_at' => null,
                    'updated_at' => $nowString,
                ], [
                    'id' => (int) $row['id'],
                    'state' => $state->value,
                    'claim_token' => $row['claim_token'],
                ]);

                $reclaimed += $affected === 1 ? 1 : 0;
            }
        }

        return $reclaimed;
    }

    /**
     * Pending rows whose moment has come.
     *
     * A sending row whose lease has expired is not returned here: it is moved
     * back to pending by reclaimExpiredLeases() first, so there is one claim
     * shape rather than two and the recovery path cannot be unconditional.
     *
     * @return list<QueuedNotification>
     */
    public function drainable(int $limit): array
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $pending = $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('state', '=', QueueState::Pending->value)
                ->where('run_at', '<=', $now)
                ->orderBy('run_at')
                ->limit($limit),
        );

        $rows = [];

        foreach ($pending as $row) {
            $nextAttempt = $row['next_attempt_at'] ?? null;

            if (\is_string($nextAttempt) && $nextAttempt !== '' && $nextAttempt > $now) {
                continue;
            }

            $rows[] = $this->hydrate($row);
        }

        return $rows;
    }

    /**
     * The claim. Zero affected rows means another runner owns it and the row
     * is skipped, which is what makes two concurrent drains over one pending
     * row produce exactly one send.
     *
     * The from-state is always one the UPDATE moves the row out of, so the
     * predicate cannot be satisfied by the value the statement writes.
     */
    public function claim(int $id, string $claimToken, string $fromState = 'pending'): bool
    {
        if ($fromState === QueueState::Sending->value) {
            throw new \InvalidArgumentException(
                'A claim from "sending" is not conditional: use reclaimExpiredLeases() and claim the pending row.',
            );
        }

        $now = $this->clock->now();
        $lease = $now->modify('+' . self::LEASE_SECONDS . ' seconds');

        $affected = $this->database->update(self::TABLE, [
            'state' => QueueState::Sending->value,
            'claim_token' => $claimToken,
            'claimed_at' => $now->format(self::DATETIME_FORMAT),
            'lease_expires_at' => $lease->format(self::DATETIME_FORMAT),
            'updated_at' => $now->format(self::DATETIME_FORMAT),
        ], ['id' => $id, 'state' => $fromState]);

        if ($affected !== 1) {
            return false;
        }

        // The attempt counter is a second statement because the port's update
        // takes values and not expressions; it runs only for the claimant, so
        // no other runner can increment the same row.
        $row = $this->find($id);

        if ($row !== null) {
            $this->database->update(self::TABLE, ['attempts' => $row->attempts + 1], ['id' => $id]);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function update(int $id, array $values): void
    {
        $this->database->update(self::TABLE, $values + [
            'updated_at' => $this->clock->now()->format(self::DATETIME_FORMAT),
        ], ['id' => $id]);
    }

    public function markSent(int $id, ?string $providerMessageId): void
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $this->update($id, [
            'state' => QueueState::Sent->value,
            'sent_at' => $now,
            'provider_message_id' => $providerMessageId,
            'claim_token' => null,
            'lease_expires_at' => null,
            'last_error' => null,
        ]);
    }

    public function markSuppressed(int $id, string $reason): void
    {
        $this->update($id, [
            'state' => QueueState::Suppressed->value,
            'suppressed_reason' => $reason,
            'claim_token' => null,
            'lease_expires_at' => null,
        ]);
    }

    public function markDeferred(int $id, \DateTimeImmutable $until): void
    {
        $this->update($id, [
            'state' => QueueState::Deferred->value,
            'next_attempt_at' => $until->format(self::DATETIME_FORMAT),
            'claim_token' => null,
            'lease_expires_at' => null,
        ]);
    }

    public function markFailedPermanent(int $id, string $error): void
    {
        $this->update($id, [
            'state' => QueueState::FailedPermanent->value,
            'last_error' => $error,
            'claim_token' => null,
            'lease_expires_at' => null,
        ]);
    }

    public function returnToPending(int $id, ?\DateTimeImmutable $nextAttemptAt, ?string $error = null): void
    {
        $this->update($id, [
            'state' => QueueState::Pending->value,
            'next_attempt_at' => $nextAttemptAt?->format(self::DATETIME_FORMAT),
            'last_error' => $error,
            'claim_token' => null,
            'lease_expires_at' => null,
        ]);
    }

    /**
     * A deferred row returns to pending at its instant rather than being
     * re-selected on every drain.
     */
    public function releaseDue(): int
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);

        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->select(['id'])
                ->where('state', '=', QueueState::Deferred->value)
                ->where('next_attempt_at', '<=', $now),
        );

        foreach ($rows as $row) {
            $this->update((int) $row['id'], ['state' => QueueState::Pending->value]);
        }

        return \count($rows);
    }

    public function cancel(int $id): bool
    {
        $affected = $this->database->update(self::TABLE, [
            'state' => QueueState::Cancelled->value,
            'updated_at' => $this->clock->now()->format(self::DATETIME_FORMAT),
        ], ['id' => $id, 'state' => QueueState::Pending->value]);

        return $affected === 1;
    }

    /**
     * The duplicate window: cancel an already-queued message for the same
     * trigger and subject. It reads real columns and never parses the event
     * reference, which is an opaque identity no policy may parse.
     */
    public function cancelPendingDuplicates(
        string $triggerKey,
        ?string $subjectType,
        ?int $subjectId,
        int $recipientUserId,
        int $exceptId,
    ): int {
        $current = $this->find($exceptId);
        if ($current === null) {
            return 0;
        }
        $query = Query::from(self::TABLE)
            ->select(['id'])
            ->where('trigger_key', '=', $triggerKey)
            ->where('recipient_user_id', '=', $recipientUserId)
            ->where('state', '=', QueueState::Pending->value)
            ->where('occurrence_key', '=', $current->occurrenceKey)
            ->where('id', '!=', $exceptId);

        $current->ruleId === null ? $query->whereNull('rule_id') : $query->where('rule_id', '=', $current->ruleId);
        $current->channelKey === null ? $query->whereNull('channel_key') : $query->where('channel_key', '=', $current->channelKey);

        if ($subjectType === null) {
            $query->whereNull('subject_type');
        } else {
            $query->where('subject_type', '=', $subjectType);
        }

        if ($subjectId === null) {
            $query->whereNull('subject_id');
        } else {
            $query->where('subject_id', '=', $subjectId);
        }

        $rows = $this->database->selectAll($query);

        foreach ($rows as $row) {
            $this->cancel((int) $row['id']);
        }

        return \count($rows);
    }

    public function countSentSince(int $recipientUserId, string $channelKey, \DateTimeImmutable $since): int
    {
        return $this->database->count(
            Query::from(self::TABLE)
                ->where('recipient_user_id', '=', $recipientUserId)
                ->where('channel_key', '=', $channelKey)
                ->where('state', '=', QueueState::Sent->value)
                ->where('sent_at', '>=', $since->format(self::DATETIME_FORMAT)),
        );
    }

    /**
     * @return array{items: list<QueuedNotification>, total: int, counts: array<string, int>}
     */
    public function page(?string $state, ?int $ruleId, int $limit, int $offset): array
    {
        $filter = static function (Query $query) use ($state, $ruleId): Query {
            if ($state !== null) {
                $query->where('state', '=', $state);
            }

            if ($ruleId !== null) {
                $query->where('rule_id', '=', $ruleId);
            }

            return $query;
        };

        $total = $this->database->count($filter(Query::from(self::TABLE)));
        $rows = $this->database->selectAll(
            $filter(Query::from(self::TABLE))->orderBy('id', Query::SORT_DESC)->limit($limit)->offset($offset),
        );

        $counts = [];

        foreach (QueueState::values() as $value) {
            $counts[$value] = $this->database->count(
                Query::from(self::TABLE)->where('state', '=', $value),
            );
        }

        return ['items' => array_map($this->hydrate(...), $rows), 'total' => $total, 'counts' => $counts];
    }

    /**
     * @return array<string, int>
     */
    public function suppressionCountsFor(int $ruleId): array
    {
        $rows = $this->database->selectAll(
            Query::from(self::TABLE)
                ->select(['suppressed_reason'])
                ->where('rule_id', '=', $ruleId)
                ->whereNotNull('suppressed_reason'),
        );

        $counts = [];

        foreach ($rows as $row) {
            $reason = (string) $row['suppressed_reason'];
            $counts[$reason] = ($counts[$reason] ?? 0) + 1;
        }

        return $counts;
    }

    public function findByProviderMessageId(string $providerMessageId): ?QueuedNotification
    {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)->where('provider_message_id', '=', $providerMessageId),
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): QueuedNotification
    {
        $parameters = [];
        $raw = $row['parameters'] ?? null;

        if (\is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            $parameters = \is_array($decoded) ? $decoded : [];
        }

        /** @var array<string, mixed> $parameters */
        return new QueuedNotification(
            (int) $row['id'],
            $row['rule_id'] === null ? null : (int) $row['rule_id'],
            (string) $row['trigger_key'],
            (string) $row['category_key'],
            NotificationClass::from((string) ($row['class'] ?? NotificationClass::Transactional->value)),
            (string) $row['template_key'],
            (string) $row['event_ref'],
            (string) $row['occurrence_key'],
            (string) $row['dedupe_key'],
            $this->instant($row['run_at'] ?? null) ?? $this->clock->now(),
            QueueState::from((string) $row['state']),
            $row['recipient_user_id'] === null ? null : (int) $row['recipient_user_id'],
            $row['channel_key'] === null ? null : (string) $row['channel_key'],
            $row['subject_type'] === null ? null : (string) $row['subject_type'],
            $row['subject_id'] === null ? null : (int) $row['subject_id'],
            $parameters,
            $row['recipient_timezone'] === null ? null : (string) $row['recipient_timezone'],
            $row['recipient_locale'] === null ? null : (string) $row['recipient_locale'],
            (int) ($row['attempts'] ?? 0),
            $this->instant($row['next_attempt_at'] ?? null),
            $row['suppressed_reason'] === null ? null : (string) $row['suppressed_reason'],
            $row['last_error'] === null ? null : (string) $row['last_error'],
            $row['provider_message_id'] === null ? null : (string) $row['provider_message_id'],
            $this->instant($row['sent_at'] ?? null),
            $this->instant($row['created_at'] ?? null),
            isset($row['recipient_address_digest']) ? (string) $row['recipient_address_digest'] : null,
        );
    }

    private function instant(mixed $value): ?\DateTimeImmutable
    {
        return \is_string($value) && $value !== ''
            ? new \DateTimeImmutable($value, new \DateTimeZone('UTC'))
            : null;
    }
}
