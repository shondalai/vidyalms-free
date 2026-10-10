<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Identity;

/**
 * The addresses the host itself vouches for, for a person.
 *
 * Optional and defaulted on Ports, like the two beside it. Without it the
 * strict invitation match rule, the just-in-time link gate and the invitation
 * context's verified addresses are three rules with no source of truth, and
 * the match falls back to comparing an unverified profile field, which is the
 * address the attacker typed.
 *
 * Default when absent: an empty list, which means **unknown** and never
 * unverified. The difference decides the direction of every rule reading it: a
 * caller must treat an empty answer as "this host cannot say", and the free
 * invitation flow therefore proves the mailbox itself with a code rather than
 * trusting a profile field or refusing everybody.
 */
interface VerifiedAddressesPort
{
    /**
     * @return list<string> lower-cased addresses, in no particular order
     */
    public function verifiedAddressesFor(int $userId): array;
}
