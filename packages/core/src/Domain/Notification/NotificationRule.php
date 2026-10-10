<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Notification;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree;

/**
 * One authored rule: what it fires on, who it reaches, when, on which
 * channels, and with which template.
 *
 * State defaults to disabled, which puts the nudge research in the schema
 * rather than in a comment: light-touch nudges are small, context dependent
 * and sometimes negative, and a site that enables five sensible rules without
 * cross-rule suppression mails a learner five times on completion day.
 *
 * The message class is deliberately absent. It is read from the trigger that
 * owns the template and never stored here, because an editable class is a
 * one-field bypass of consent, of the unsubscribe headers and of the refusal
 * to force a marketing category.
 */
final class NotificationRule
{
    public const STATE_ENABLED = 'enabled';
    public const STATE_DISABLED = 'disabled';

    public const KIND_IMMEDIATE = 'immediate';
    public const KIND_ABSOLUTE = 'absolute';
    public const KIND_RELATIVE = 'relative';
    public const KIND_RECURRING_WINDOW = 'recurring_window';

    /**
     * @param list<string>         $channels
     * @param list<string>         $suppresses
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $audienceConfig
     * @param array<string, mixed> $schedule
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $ruleKey,
        public readonly string $triggerKey,
        public readonly string $audienceKey,
        public readonly string $categoryKey,
        public readonly string $templateKey,
        public readonly array $channels,
        public readonly string $scheduleKind = self::KIND_IMMEDIATE,
        public readonly ?string $anchorKey = null,
        public readonly array $schedule = [],
        public readonly array $definition = [],
        public readonly array $audienceConfig = [],
        public readonly ?AvailabilityTree $conditions = null,
        public readonly array $suppresses = [],
        public readonly ?int $courseId = null,
        public readonly ?int $cohortId = null,
        public readonly string $state = self::STATE_DISABLED,
        public readonly int $queuedCount = 0,
        public readonly int $sentCount = 0,
        public readonly int $suppressedCount = 0,
        public readonly ?int $createdBy = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->state === self::STATE_ENABLED;
    }

    /**
     * @return list<string>
     */
    public static function scheduleKinds(): array
    {
        return [self::KIND_IMMEDIATE, self::KIND_ABSOLUTE, self::KIND_RELATIVE, self::KIND_RECURRING_WINDOW];
    }

    /**
     * @return list<string>
     */
    public static function states(): array
    {
        return [self::STATE_ENABLED, self::STATE_DISABLED];
    }
}
