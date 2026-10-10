<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

/**
 * The default: this host cannot say which addresses it has verified.
 *
 * An empty list means unknown. It does not mean the person has no verified
 * address, and no rule may read it as a denial, which is why the free
 * invitation flow proves the mailbox with its own code instead of consulting
 * this and refusing.
 */
final class NoVerifiedAddresses implements VerifiedAddressesPort
{
    /**
     * @return list<string>
     */
    public function verifiedAddressesFor(int $userId): array
    {
        return [];
    }
}
