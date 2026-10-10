<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access;

interface EntitlementRepository
{
    public function find(int $id): ?Entitlement;

    /**
     * The idempotency lookup for shop callbacks and imports: one entitlement
     * per (source, source_ref) pair.
     */
    public function findBySourceRef(string $source, string $sourceRef): ?Entitlement;

    /**
     * The row a unique-index violation actually collided with.
     *
     * The index is carried on source_ref_digest, so a catch-and-re-read that
     * looked the row up by (source, source_ref) would be asking a different
     * question from the one the database answered: the two agree today and
     * would stop agreeing the moment anything wrote a digest without the pair
     * that produced it, and the re-read would then find nothing and report a
     * grant that never happened on a path where money has already moved.
     */
    public function findBySourceRefDigest(string $digest): ?Entitlement;

    /**
     * Persists a new entitlement and returns it with its generated id.
     */
    public function add(Entitlement $entitlement): Entitlement;

    /**
     * Writes a new status, and the reason and external key that go with it.
     *
     * The two trailing parameters are additive and default to null, which
     * leaves every shipped caller unchanged. They exist because
     * entitlements.revoked_reason and revoked_ref had no writer at all: the
     * whole suspension-reason read model resolved to nothing, and a
     * dispute-driven revocation had nowhere to record the dispute a won
     * outcome would have to reinstate against. A null leaves whatever is
     * stored alone rather than erasing it.
     */
    public function updateStatus(int $id, string $status, ?string $reason = null, ?string $ref = null): void;

    /**
     * Flips one entitlement to expired only while the stored row still
     * matches the snapshot that was read: same expiry, still active. Returns
     * false when it does not, which is how the sweep's read-then-write stops
     * overwriting an extend, a revoke or another sweep committed in between.
     *
     * The snapshot is the comparand rather than the id alone, because an
     * extend leaves the status active and only moves expires_at.
     */
    public function expireIfStillDue(Entitlement $entitlement, \DateTimeImmutable $moment): bool;

    /**
     * Writes a new expiry (null meaning never) and the status that goes with
     * it, which is how an expired entitlement is brought back to active.
     */
    public function updateExpiry(int $id, ?\DateTimeImmutable $expiresAt, string $status): void;

    /**
     * @return list<Entitlement>
     */
    public function activeForUserAndCourse(int $userId, int $courseId): array;

    /**
     * Every active entitlement whose expires_at is at or before the moment.
     *
     * @return list<Entitlement>
     */
    public function activeExpiringBy(\DateTimeImmutable $moment): array;

    /**
     * Every active, future-provisioned entitlement (starts_at not null) whose
     * start has arrived by the moment. The scheduler sweep enrols these.
     *
     * @return list<Entitlement>
     */
    public function activeScheduledBy(\DateTimeImmutable $moment): array;
}
