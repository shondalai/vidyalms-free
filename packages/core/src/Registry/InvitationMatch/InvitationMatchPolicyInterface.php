<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\InvitationMatch;

use Shondalai\VidyaLMS\Core\Registry\Context\InvitationMatchContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * Which account may accept an invitation addressed to an address.
 *
 * The free rule is a strict match, and its implementation is the encrypted
 * invitation delivery and mailbox proof ceremony rather than the design's
 * verified-address gate: Joomla's authentication response carries no verified
 * address metadata at all, so a rule keyed on "an address the host has
 * verified" would have been unreachable on one of the two hosts in every
 * edition. Fresh possession of the invited mailbox, proved inside a ten
 * minute window and bound to one signed-in account, is the stronger property
 * and it is the one the free provider asserts.
 *
 * Miss direction: fail closed: acceptance is refused. A gate nobody can
 * answer answers no, and the refusal is the same indistinguishable answer an
 * unknown token gets, so the endpoint still cannot enumerate.
 *
 * The decision shape is settled now that a provider ships: this interface may
 * not gain a method. A policy that needs to say more says it by declaring a
 * capability interface beside this one, which is probed with instanceof.
 */
interface InvitationMatchPolicyInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * Whether this account may use this invitation.
     *
     * The policy decides identity and never possession: the token hash and
     * the proof code have already been compared in constant time by the time
     * this is asked, and neither raw secret is on the context.
     */
    public function matches(InvitationMatchContext $context): bool;
}
