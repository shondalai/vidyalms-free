<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access;

/**
 * Why a person may take a course. An entitlement never represents the act of
 * taking it; that is the Enrolment. Expiring or revoking an entitlement can
 * suspend the enrolment but never deletes progress.
 */
final class Entitlement
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_OPEN = 'open';
    public const SOURCE_PURCHASE = 'purchase';
    public const SOURCE_SUBSCRIPTION = 'subscription';
    public const SOURCE_COUPON = 'coupon';
    public const SOURCE_SEAT = 'seat';
    public const SOURCE_IMPORT = 'import';
    public const SOURCE_ORG = 'org';

    /**
     * The words this core version stores.
     *
     * Storable and grantable are two different questions, and only the second
     * is the registry's. This list is what the column holds, what every
     * report groups by and what a screen labels, so widening it stays a core
     * decision with a migration behind it. **Which of these words a build may
     * grant under** is resolved through the CommerceSource registry: a source
     * declaring GrantsEntitlements names the words its own callbacks arrive
     * under, so a package registering its own checkout grants without a core
     * edit, and a build with no commerce source registered grants only the
     * two staff paths.
     */
    public const SOURCES = [
        self::SOURCE_MANUAL,
        self::SOURCE_OPEN,
        self::SOURCE_PURCHASE,
        self::SOURCE_SUBSCRIPTION,
        self::SOURCE_COUPON,
        self::SOURCE_SEAT,
        self::SOURCE_IMPORT,
        self::SOURCE_ORG,
    ];

    /**
     * What a source word has to look like, beside being one of the eight.
     *
     * The width matches entitlements.source (string:30), so a word this
     * constructor accepts is a word the column can hold, and a widening that
     * forgets the column is caught here rather than truncated by the engine.
     */
    private const SOURCE_PATTERN = '/^[a-z][a-z0-9_.-]{0,29}$/';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_EXPIRED,
        self::STATUS_REVOKED,
    ];

    public function __construct(
        private readonly ?int $id,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly string $source,
        public readonly ?string $sourceRef,
        public readonly string $status,
        public readonly ?int $grantedBy,
        public readonly ?\DateTimeImmutable $startsAt,
        public readonly ?\DateTimeImmutable $expiresAt,
        public readonly ?int $organisationId = null,
        /**
         * Why this entitlement stopped conferring access.
         *
         * A real field because the suspension panel, the admin list and the
         * refunds report all read it, and because a revocation had nowhere to
         * record its reason except the domain event, which is a delivery
         * rather than a record.
         */
        public readonly ?string $revokedReason = null,
        /**
         * What the revocation was keyed on, in the external system's terms.
         *
         * A dispute-driven revocation has to be keyed on the dispute, so a
         * won outcome knows what to reinstate. Naming the entitlement's own
         * id here is a self reference and answers nothing.
         */
        public readonly ?string $revokedRef = null,
    ) {
        if ($userId <= 0) {
            throw new \InvalidArgumentException('An entitlement needs a positive user id.');
        }

        if ($courseId <= 0) {
            throw new \InvalidArgumentException('An entitlement needs a positive course id.');
        }
        if ($organisationId !== null && $organisationId < 1) {
            throw new \InvalidArgumentException('An organisation reference must be positive or absent.');
        }

        if (preg_match(self::SOURCE_PATTERN, $source) !== 1 || !\in_array($source, self::SOURCES, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unknown entitlement source "%s".', $source));
        }

        if (!\in_array($status, self::STATUSES, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \InvalidArgumentException(sprintf('Unknown entitlement status "%s".', $status));
        }
    }

    public static function grant(
        int $userId,
        int $courseId,
        string $source,
        ?string $sourceRef = null,
        ?int $grantedBy = null,
        ?\DateTimeImmutable $startsAt = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): self {
        return new self(null, $userId, $courseId, $source, $sourceRef, self::STATUS_ACTIVE, $grantedBy, $startsAt, $expiresAt);
    }

    public function id(): int
    {
        if ($this->id === null) {
            throw new \LogicException('The entitlement has not been persisted yet.');
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

    public function isExpiredAt(\DateTimeImmutable $moment): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->expiresAt !== null
            && $this->expiresAt <= $moment;
    }

    public function hasStartedBy(\DateTimeImmutable $moment): bool
    {
        return $this->startsAt === null || $this->startsAt <= $moment;
    }

    /**
     * Whether this entitlement actually confers access at the given moment:
     * active, started, and not past its expiry. A future-dated grant exists
     * but is not yet effective.
     */
    public function isEffectiveAt(\DateTimeImmutable $moment): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->hasStartedBy($moment)
            && !$this->isExpiredAt($moment);
    }

    public function withId(int $id): self
    {
        return new self(
            $id,
            $this->userId,
            $this->courseId,
            $this->source,
            $this->sourceRef,
            $this->status,
            $this->grantedBy,
            $this->startsAt,
            $this->expiresAt,
            $this->organisationId,
            $this->revokedReason,
            $this->revokedRef,
        );
    }

    public function withStatus(string $status, ?string $reason = null, ?string $ref = null): self
    {
        return new self(
            $this->id,
            $this->userId,
            $this->courseId,
            $this->source,
            $this->sourceRef,
            $status,
            $this->grantedBy,
            $this->startsAt,
            $this->expiresAt,
            $this->organisationId,
            $reason ?? $this->revokedReason,
            $ref ?? $this->revokedRef,
        );
    }
}
