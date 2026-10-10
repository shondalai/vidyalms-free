<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access;

/**
 * The fact that a person is taking a course, unique per user and course.
 * Losing the last active entitlement suspends the enrolment; the row and all
 * progress attached to it survive.
 */
final class Enrolment
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_SUSPENDED,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
    ];

    public function __construct(
        private readonly ?int $id,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly int $entitlementId,
        public readonly string $status,
        public readonly \DateTimeImmutable $enrolledAt,
        public readonly ?\DateTimeImmutable $completedAt,
        public readonly ?\DateTimeImmutable $lastActivityAt,
        public readonly float $progressPercent,
    ) {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('An enrolment needs a positive user id.');
        }

        if ($courseId <= 0) {
            throw new \InvalidArgumentException('An enrolment needs a positive course id.');
        }

        if ($entitlementId <= 0) {
            throw new \InvalidArgumentException('An enrolment needs a positive entitlement id.');
        }

        if (!\in_array($status, self::STATUSES, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unknown enrolment status "%s".', $status));
        }
    }

    public static function start(int $userId, int $courseId, int $entitlementId, \DateTimeImmutable $enrolledAt): self
    {
        return new self(null, $userId, $courseId, $entitlementId, self::STATUS_ACTIVE, $enrolledAt, null, null, 0.0);
    }

    public function id(): int
    {
        if ($this->id === null) {
            throw new \LogicException('The enrolment has not been persisted yet.');
        }

        return $this->id;
    }

    public function hasId(): bool
    {
        return $this->id !== null;
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->userId,
            $this->courseId,
            $this->entitlementId,
            $this->status,
            $this->enrolledAt,
            $this->completedAt,
            $this->lastActivityAt,
            $this->progressPercent,
        );
    }

    public function withStatus(string $status): self
    {
        return new self(
            $this->id,
            $this->userId,
            $this->courseId,
            $this->entitlementId,
            $status,
            $this->enrolledAt,
            $this->completedAt,
            $this->lastActivityAt,
            $this->progressPercent,
        );
    }

    public function resumedWith(int $entitlementId): self
    {
        return new self(
            $this->id,
            $this->userId,
            $this->courseId,
            $entitlementId,
            self::STATUS_ACTIVE,
            $this->enrolledAt,
            $this->completedAt,
            $this->lastActivityAt,
            $this->progressPercent,
        );
    }
}
