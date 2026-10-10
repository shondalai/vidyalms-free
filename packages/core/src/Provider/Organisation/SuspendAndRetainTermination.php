<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Registry\Context\OrgMembershipTerminationContext;
use Shondalai\VidyaLMS\Core\Registry\OrgMembershipTermination\OrgMembershipTerminationPolicyInterface;

/**
 * The free build's closure: the record stays and the access goes.
 *
 * A membership that leaves the active state keeps its row, its events and its
 * history, because "who had access on the third of March" is a question a
 * regulator asks and a deleted row cannot answer. What goes back is what the
 * organisation paid for: its own entitlements are revoked, the seats it was
 * holding return to their pools, and any attempt still open is closed under
 * the published disposition rather than stranded.
 *
 * This is what the free build already did. What it did not have was anywhere
 * to say so: the seam was seated and nothing consulted it, so a Business
 * policy offering a notice period had nothing to register into.
 */
final class SuspendAndRetainTermination implements OrgMembershipTerminationPolicyInterface
{
    public const KEY = 'suspend_and_retain';

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'org.membership_termination.suspend_and_retain';
    }

    public function releasesAccess(OrgMembershipTerminationContext $context): bool
    {
        return true;
    }
}
