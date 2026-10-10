<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgMembershipTermination;

use Shondalai\VidyaLMS\Core\Registry\Context\OrgMembershipTerminationContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * What closing a membership does to the access it sourced.
 *
 * The free shape retains the membership record and releases the organisation's
 * own access with it, which is what `suspend_and_retain` means: the row stays
 * so the history is answerable, and the grants and seats the organisation paid
 * for go back. A paid policy may keep the access alive through a notice
 * period, or release it and keep the seat held.
 *
 * Miss direction: fail closed: the membership cannot be closed and the screen names the provider.
 *
 * The seam had no provider and no reader at all until the last build wave: it
 * was seated, published in the edition table, and consulted by nothing, so a
 * Business registration into it was a silent no-op.
 * {@see releasesAccess()} is the decision shape and the member update is the
 * reader.
 */
interface OrgMembershipTerminationPolicyInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * Whether closing this membership releases the access it sourced.
     *
     * True runs the shipped termination: the organisation's own entitlements
     * are revoked, the seats it was holding go back to their pools and any
     * open attempt is closed under the published disposition. False leaves
     * every one of them alone, which is a notice period rather than a
     * closure, and the caller records the policy that said so on the
     * membership event either way.
     */
    public function releasesAccess(OrgMembershipTerminationContext $context): bool;
}
