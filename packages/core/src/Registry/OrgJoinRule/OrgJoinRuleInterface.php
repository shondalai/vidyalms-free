<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgJoinRule;

use Shondalai\VidyaLMS\Core\Registry\Context\OrgJoinContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * How a person becomes a member of an organisation.
 *
 * The free build ships one rule and it is the strict one: a person joins
 * because somebody invited them and they proved they hold the mailbox. Pro
 * adds joining from a verified claimed domain, and Business adds joining from
 * an authoritative directory assertion.
 *
 * **A rule that depends on a verified domain declares a capability and asks at
 * decision time whether a resolver is seated.** `VerifiesDomain` ships in
 * `packages/pro-core` and `VerifiedDomainJoinRule` declares it, probed with
 * `instanceof` like every other capability in this design.
 *
 * The guarantee is a DECISION-TIME probe and not a save-time refusal, and the
 * difference is structural rather than a preference: a package seats its
 * `DomainVerificationPort` through the port-contribution moment
 * ({@see \Shondalai\VidyaLMS\Core\Boot\PortsReady}), which fires while `Ports`
 * is still being assembled and therefore after the moment a save would want to
 * probe. So the rule asks the container whether anything can prove a domain at
 * all, and refuses everything but the free invitation floor when nothing can.
 *
 * Miss direction: fail closed. Nobody joins, and the rule is listed as
 * unresolved. This is a gate, so the answer to "may this person join?" when
 * nobody can answer is no.
 */
interface OrgJoinRuleInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * Whether this join may proceed.
     *
     * The context carries what the caller already proved rather than the means
     * to prove it: a rule provider never queries, so a third-party rule cannot
     * read an organisation-scoped table with no predicate.
     */
    public function admits(OrgJoinContext $context): bool;
}
