<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Expiry;

use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * When a credential stops being in force.
 *
 * Expiry is set on the template or the course and never per certificate by
 * hand, which is Moodle's badge expiry vocabulary and the right one: a date
 * typed onto one learner's certificate is a date nobody can audit. What that
 * setting means is a policy, because the shapes a real regime needs go beyond
 * the three the column can express: a CPE certificate that lapses at the end
 * of the reporting year and a licence that lapses on its anniversary are both
 * "expires", and both are wrong if computed as a fixed number of days.
 *
 * **Expiry is data and never a status.** The policy answers an instant that
 * lands on validUntil, and whether the credential is expired is then a
 * comparison against the clock made at read time. A stored "expired" flag
 * would go stale the moment a clock moved, which is exactly why the status
 * enumeration derives that value rather than storing it.
 *
 * The seam is here rather than in the paid package because it is resolved at
 * issue, by free code, on every site. The free build computes the three
 * shapes the column's own vocabulary names (never, fixed, days_after_issue)
 * and freezes the answer into the snapshot; a paid policy is a registration
 * that the same free call site resolves. Split the other way, a template
 * naming a paid shape fell through a core if-chain to no expiry, froze that
 * absence into the artefact, and then had a date stamped onto the row by a
 * daily sweep that the holder's own certificate never mentioned.
 *
 * The miss direction: an unresolved policy answers null, which is "never
 * expires". Failing the other way would silently expire every credential a
 * site issued under a policy whose package was removed, and a credential
 * wrongly expired is a credential wrongly refused.
 */
interface ExpiryPolicyInterface extends ProviderInterface
{
    public function label(): string;

    /**
     * The instant this credential stops being in force, or null when it never
     * does.
     */
    public function expiresAt(ExpiryContext $context): ?\DateTimeImmutable;

    /**
     * Whether this policy can answer with what the template configured. A
     * policy that needs a day count and has none says so at save time rather
     * than answering null at issue, when nobody is watching.
     */
    public function isConfigured(ExpiryContext $context): bool;
}
