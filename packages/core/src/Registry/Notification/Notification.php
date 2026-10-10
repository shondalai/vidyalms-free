<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification;

/**
 * One message to one person about one occurrence. The event reference is the
 * originating domain event's idempotency key, or a minted synthetic reference
 * where no domain event exists, so the queue can guarantee one delivery per
 * occurrence.
 *
 * Three members were added as trailing, defaulted parameters rather than as a
 * second message type, so every existing call site keeps compiling: the
 * message class, the category the preference centre groups by, and the
 * occurrence this delivery belongs to.
 *
 * The parameter bag is array<string, mixed> and no longer array<string,
 * scalar|null>, because a digest carries a list of items and a scalar bag
 * cannot hold one. The rule that replaces scalarity is JSON safety: a
 * parameter must survive a round trip through json_encode, because the queue
 * row stores it and the render happens later in another process.
 * MailMessage widened in the same release, since the email channel passes
 * these straight into one.
 */
final class Notification
{
    /** What an occurrence key says when the schedule fires once and only once. */
    public const OCCURRENCE_ONCE = 'once';

    /**
     * @param array<string, mixed> $parameters JSON-safe; a non-scalar is a decoded structure at the host boundary
     */
    public function __construct(
        public readonly int $recipientUserId,
        public readonly string $templateKey,
        public readonly array $parameters,
        public readonly string $eventReference,
        public readonly NotificationClass $class = NotificationClass::Transactional,
        public readonly string $categoryKey = 'account',
        public readonly string $occurrenceKey = self::OCCURRENCE_ONCE,
    ) {
    }

    /**
     * The same message with a different recipient, which is what the fan-out
     * writes once per resolved audience member.
     */
    public function forRecipient(int $recipientUserId): self
    {
        return new self(
            $recipientUserId,
            $this->templateKey,
            $this->parameters,
            $this->eventReference,
            $this->class,
            $this->categoryKey,
            $this->occurrenceKey,
        );
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function withParameters(array $parameters): self
    {
        return new self(
            $this->recipientUserId,
            $this->templateKey,
            $parameters,
            $this->eventReference,
            $this->class,
            $this->categoryKey,
            $this->occurrenceKey,
        );
    }
}
