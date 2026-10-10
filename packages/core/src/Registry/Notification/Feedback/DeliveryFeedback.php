<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Feedback;

/**
 * One neutral delivery event, whatever format it arrived in.
 *
 * A new ESP is a new mapper and touches nothing else, because no use case
 * ever sees a foreign vocabulary: the foreign format stops at the mapper.
 */
final class DeliveryFeedback
{
    public const TYPE_DELIVERED = 'delivered';
    public const TYPE_HARD_BOUNCE = 'hard_bounce';
    public const TYPE_SOFT_BOUNCE = 'soft_bounce';
    public const TYPE_COMPLAINT = 'complaint';
    public const TYPE_UNSUBSCRIBE = 'unsubscribe';

    public function __construct(
        public readonly string $type,
        public readonly ?string $recipient = null,
        public readonly ?string $providerMessageId = null,
        public readonly ?\DateTimeImmutable $occurredAt = null,
        public readonly ?string $diagnostic = null,
    ) {
    }

    /**
     * @return list<string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_DELIVERED,
            self::TYPE_HARD_BOUNCE,
            self::TYPE_SOFT_BOUNCE,
            self::TYPE_COMPLAINT,
            self::TYPE_UNSUBSCRIBE,
        ];
    }

    /**
     * A hard bounce and a complaint suppress permanently until the address is
     * re-verified. A soft bounce increments a counter and suppresses at a
     * configurable threshold, because one deferral is not a deliverability
     * fact.
     */
    public function suppressesImmediately(): bool
    {
        return $this->type === self::TYPE_HARD_BOUNCE || $this->type === self::TYPE_COMPLAINT;
    }
}
