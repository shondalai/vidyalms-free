<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

/**
 * The channel's report on one delivery attempt.
 *
 * The provider message id is what later correlates an asynchronous bounce or
 * complaint back to the queue row; retryAfter is the channel's own hint,
 * which the queue may honour but never has to, because retry is owned by the
 * queue and by nothing else.
 */
final class SendResult
{
    private function __construct(
        public readonly SendOutcome $outcome,
        public readonly ?string $providerMessageId = null,
        public readonly ?\DateTimeImmutable $retryAfter = null,
        public readonly ?string $error = null,
        public readonly bool $degraded = false,
    ) {
    }

    public static function sent(?string $providerMessageId = null, bool $degraded = false): self
    {
        return new self(SendOutcome::Sent, $providerMessageId, null, null, $degraded);
    }

    public static function deferred(\DateTimeImmutable $until, ?string $reason = null): self
    {
        return new self(SendOutcome::Deferred, null, $until, $reason);
    }

    public static function failedTransient(string $error, ?\DateTimeImmutable $retryAfter = null): self
    {
        return new self(SendOutcome::FailedTransient, null, $retryAfter, $error);
    }

    public static function failedPermanent(string $error): self
    {
        return new self(SendOutcome::FailedPermanent, null, null, $error);
    }

    public function isSent(): bool
    {
        return $this->outcome === SendOutcome::Sent;
    }
}
