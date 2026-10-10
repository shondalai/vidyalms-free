<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Registry\Context\InvitationMatchContext;
use Shondalai\VidyaLMS\Core\Registry\InvitationMatch\InvitationMatchPolicyInterface;
use Shondalai\VidyaLMS\Core\Registry\InvitationMatch\InvitationMatchRegistry;

/**
 * The free rule: the accepting account's own address must equal the invited
 * address, at every step of the ceremony.
 *
 * It replaces three hardcoded comparisons that were spelled out by hand in
 * three files, and the point of moving them here is not tidiness. They were
 * three independent copies of one rule, so a site that wanted the design's
 * `relaxed` policy had three places to patch and a fourth comparison added
 * later would have been under no rule at all.
 *
 * **The comparison is the whole provider, and the ceremony around it is the
 * implementation of the design's `strict_email`.** The design asks for a
 * strict match "against an address the host has verified"; Joomla's
 * AuthenticationResponse carries no verified-address metadata, so that rule
 * would have been unreachable on one of the two hosts. What the free build
 * asserts instead is stronger and host-independent: a fresh secret is sent to
 * the invited mailbox, it expires in ten minutes, it is bound to one
 * signed-in account and to one invitation revision, and it is spent on use.
 * Possession of the current invited mailbox is proved rather than asserted.
 *
 * A relaxed provider, when one ships, replaces this key's decision and
 * inherits none of the ceremony's guarantees. That is why the ceremony's
 * refusals live in the use case and only the identity question lives here: a
 * policy that could turn off the mailbox proof would be a policy that can
 * turn off the property this seam exists to protect.
 */
final class StrictEmailInvitationMatch implements InvitationMatchPolicyInterface
{
    public function key(): string
    {
        return InvitationMatchRegistry::DEFAULT_KEY;
    }

    public function label(): string
    {
        return 'vidyalms.invitation_match.strict_email';
    }

    /**
     * Both addresses arrive normalised from the context, so this is a
     * comparison and never a second normalisation with its own opinion.
     *
     * hash_equals rather than a string comparison: an address is not a
     * secret, but the answer is a gate, and a timing-independent comparison
     * on a gate costs nothing and removes an argument.
     */
    public function matches(InvitationMatchContext $context): bool
    {
        if ($context->invitedEmail === '' || $context->accountEmail === '') {
            return false;
        }

        return hash_equals($context->invitedEmail, $context->accountEmail);
    }
}
