<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;

/**
 * A repository that lets a test commit a write between the sweep's read of
 * the due list and the sweep's write over it. Everything delegates; the one
 * addition is the callback fired after activeExpiringBy() has taken its
 * snapshot, which is exactly where a concurrent extend lands in production.
 */
final class InterleavingEntitlementRepository implements EntitlementRepository
{
    /** @var callable():void|null */
    private $afterDueRead = null;

    public function __construct(private readonly EntitlementRepository $inner)
    {
    }

    /**
     * Runs once, after the next read of the due list.
     *
     * @param callable():void $interleaved
     */
    public function interleaveAfterDueRead(callable $interleaved): void
    {
        $this->afterDueRead = $interleaved;
    }

    public function find(int $id): ?Entitlement
    {
        return $this->inner->find($id);
    }

    public function findBySourceRef(string $source, string $sourceRef): ?Entitlement
    {
        return $this->inner->findBySourceRef($source, $sourceRef);
    }

    public function findBySourceRefDigest(string $digest): ?Entitlement
    {
        return $this->inner->findBySourceRefDigest($digest);
    }

    public function add(Entitlement $entitlement): Entitlement
    {
        return $this->inner->add($entitlement);
    }

    public function updateStatus(int $id, string $status, ?string $reason = null, ?string $ref = null): void
    {
        $this->inner->updateStatus($id, $status, $reason, $ref);
    }

    public function updateExpiry(int $id, ?\DateTimeImmutable $expiresAt, string $status): void
    {
        $this->inner->updateExpiry($id, $expiresAt, $status);
    }

    public function expireIfStillDue(Entitlement $entitlement, \DateTimeImmutable $moment): bool
    {
        return $this->inner->expireIfStillDue($entitlement, $moment);
    }

    public function activeForUserAndCourse(int $userId, int $courseId): array
    {
        return $this->inner->activeForUserAndCourse($userId, $courseId);
    }

    public function activeExpiringBy(\DateTimeImmutable $moment): array
    {
        $due = $this->inner->activeExpiringBy($moment);
        $interleaved = $this->afterDueRead;

        if ($interleaved !== null) {
            $this->afterDueRead = null;
            $interleaved();
        }

        return $due;
    }

    public function activeScheduledBy(\DateTimeImmutable $moment): array
    {
        return $this->inner->activeScheduledBy($moment);
    }
}
