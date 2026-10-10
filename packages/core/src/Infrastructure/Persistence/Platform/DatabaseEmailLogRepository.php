<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\EmailLogRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseEmailLogRepository implements EmailLogRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function insertQueued(
        ?int $userId,
        string $templateKey,
        string $recipient,
        ?string $eventRef,
        int $attempt = 1,
    ): ?int {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $dedupeKey = $eventRef === null ? null : self::dedupeKey($templateKey, $recipient, $eventRef);
        $attempt = max(1, $attempt);

        try {
            return $this->database->insert('email_log', [
                'user_id' => $userId,
                'template_key' => $templateKey,
                'recipient' => $recipient,
                'status' => 'queued',
                'event_ref' => $eventRef,
                'dedupe_key' => $dedupeKey,
                'attempt' => $attempt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $failure) {
            // A NULL dedupe key never collides on the unique index, so the
            // failure cannot be a duplicate occurrence. With a digest, the
            // collision is now on (digest, attempt): the same attempt of the
            // same occurrence, which is a replay and not a retry.
            if ($dedupeKey !== null && $this->attemptExists($dedupeKey, $attempt)) {
                return null;
            }

            throw $failure;
        }
    }

    public function markSent(int $id): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->database->update('email_log', [
            'status' => 'sent',
            'sent_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $this->database->update('email_log', [
            'status' => 'failed',
            'error' => $error,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    /**
     * One occurrence, one row: the unique index lives on this digest because
     * the three underlying columns together exceed legacy MySQL index limits.
     */
    private static function dedupeKey(string $templateKey, string $recipient, string $eventRef): string
    {
        return hash('sha256', $eventRef . '|' . $templateKey . '|' . strtolower($recipient));
    }

    private function attemptExists(string $dedupeKey, int $attempt): bool
    {
        return $this->database->count(
            Query::from('email_log')
                ->where('dedupe_key', '=', $dedupeKey)
                ->where('attempt', '=', $attempt),
        ) > 0;
    }
}
