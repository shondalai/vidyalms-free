<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Organisation;

use Shondalai\VidyaLMS\Core\Registry\Context\OrgJoinContext;
use Shondalai\VidyaLMS\Core\Registry\OrgJoinRule\OrgJoinRuleInterface;

/**
 * The free build's only way into an organisation: somebody invited you and you
 * proved you hold the mailbox.
 *
 * It is a provider over behaviour the free build already had rather than a new
 * gate. The acceptance path used to be the rule; now the acceptance path asks
 * the rule, so a site that removes this provider stops admitting anybody
 * instead of quietly falling back to admitting everybody.
 *
 * It admits nothing but a fresh invitation proof. A verified domain and a
 * directory assertion are other rules in other editions, and this one refuses
 * them by name rather than by omission.
 */
final class InvitationOnlyJoinRule implements OrgJoinRuleInterface
{
    public function key(): string
    {
        return 'invitation_only';
    }

    public function label(): string
    {
        return 'org.join_rule.invitation_only';
    }

    public function admits(OrgJoinContext $context): bool
    {
        return $context->proof === OrgJoinContext::PROOF_INVITATION;
    }
}
