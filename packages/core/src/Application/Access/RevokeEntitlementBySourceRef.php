<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository;

/**
 * Revokes the entitlement a selling system knows by its own reference.
 *
 * Shop bridges share this lookup and the learning access lifecycle, so a
 * revocation preserves other valid grants and records its reason consistently.
 *
 * It resolves through the repository lookup that already exists and then
 * delegates to RevokeEntitlement, so the last-effective-entitlement check and
 * the EnrolmentSuspended emission happen in exactly one place. A reference
 * nothing matches answers null rather than throwing: a shop reporting a
 * refund for an order this LMS never granted against is a normal path, and an
 * exception out of a webhook drain is a retry loop.
 */
final class RevokeEntitlementBySourceRef
{
    public function __construct(
        private readonly EntitlementRepository $entitlements,
        private readonly RevokeEntitlement $revoke,
    ) {
    }

    /**
     * @param string  $reason a revocation reason the read model can label
     * @param ?string $ref    the external key this revocation is about (a
     *                        dispute id, a refund id), so a dispute that is
     *                        later won knows what to reinstate. It is not the
     *                        entitlement's own id: a self reference answers
     *                        nothing.
     */
    public function execute(
        Principal $actor,
        string $source,
        string $sourceRef,
        string $reason = 'revoked',
        ?string $ref = null,
    ): ?Entitlement {
        $entitlement = $this->entitlements->findBySourceRef($source, $sourceRef);

        if ($entitlement === null) {
            return null;
        }

        return $this->revoke->execute($actor, $entitlement->id(), $reason, $ref);
    }
}
