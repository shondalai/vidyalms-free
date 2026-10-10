<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

/**
 * The per-attempt delivery record.
 *
 * It stopped being the enqueue point when the notification queue took that
 * job, and its unique index changed with it. Its digest is over the event
 * reference, the template and the recipient, and none of those varies between
 * attempts, so under a per-message unique index a retry after a transient
 * failure either threw inside the send path after the mail had already gone
 * out or silently upserted and lost the evidence that a retry happened, which
 * is the one thing a per-attempt record exists to hold. The index is now
 * (dedupe_key, attempt).
 */
interface EmailLogRepository
{
    /**
     * Inserts a queued row and returns its id, or null when this attempt of
     * this occurrence was already logged for this template and recipient.
     *
     * The attempt defaults to one, so every existing call site keeps its
     * previous meaning: one occurrence, one row.
     */
    public function insertQueued(
        ?int $userId,
        string $templateKey,
        string $recipient,
        ?string $eventRef,
        int $attempt = 1,
    ): ?int;

    public function markSent(int $id): void;

    public function markFailed(int $id, string $error): void;
}
