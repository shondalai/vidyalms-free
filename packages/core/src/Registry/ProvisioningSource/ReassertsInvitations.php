<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ProvisioningSource;

/**
 * A provisioning source that keeps re-asserting an invitation it owns.
 *
 * GitHub's rule is that an invitation an active source is still sending is
 * renewed by the next sync rather than expiring silently, and the reason is
 * the one this product cares about: an operator whose directory still lists a
 * person, watching invitations to that person quietly expire on a seven day
 * clock, has an outage they cannot see. The carve-out is copied narrowly:
 * only a registered source may claim a row, only for the organisation it
 * governs, and a claim exempts the row from the expiry sweep and nothing
 * else.
 *
 * It is a capability probed with instanceof rather than a method on the
 * shipped ProvisioningSourceInterface, because that interface is published
 * and a directory that merely names a provenance has nothing to say here.
 * **When no registered source declares it, or none claims the row, the
 * invitation expires**, which is today's behaviour on every free build: the
 * ProvisioningSource registry ships empty, so the sweep asks nobody and
 * expires everything due. That is the published normal path rather than a
 * fallback, and the probe exists now so the wave that ships a directory does
 * not have to change the sweep.
 */
interface ReassertsInvitations
{
    /**
     * Whether this source is still asserting an invitation to this address.
     *
     * The address rather than the invitation id, because a directory knows
     * people and not this product's row ids, and the organisation because a
     * source governs one tenant's rows and may never answer for another's.
     *
     * A source that cannot answer right now must answer false: the safe
     * direction is to let an invitation expire and be reissued by the next
     * sync, not to keep a bearer token alive because a directory was briefly
     * unreachable.
     */
    public function reassertsInvitation(int $organisationId, string $email): bool;
}
