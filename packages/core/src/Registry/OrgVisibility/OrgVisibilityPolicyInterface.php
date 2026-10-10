<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\OrgVisibility;

use Shondalai\VidyaLMS\Core\Registry\Context\OrgVisibilityContext;
use Shondalai\VidyaLMS\Core\Registry\ProviderInterface;

/**
 * What an organisation-scoped reader may be shown about a person.
 *
 * The free answer is organisation-sourced rows only: a self-purchased
 * enrolment is invisible to the employer. A Business policy widens it to
 * rows the learner declared, and never further.
 *
 * Miss direction: fail closed: nothing is disclosed.
 *
 * The seam had no provider and no reader at all until the last build wave: it
 * was seated, published in the edition table, and consulted by nothing, so a
 * Business registration into it was a silent no-op. {@see visibleSources()} is
 * the decision shape and the free learning list is the reader.
 */
interface OrgVisibilityPolicyInterface extends ProviderInterface
{
    /** A language key, resolved through TranslatorPort by whatever shows it. */
    public function label(): string;

    /**
     * Entitlement sources this reader may be shown BEYOND the organisation's
     * own rows.
     *
     * The organisation's own grants are never in the answer, because they are
     * not a disclosure decision: an organisation may always see what it
     * granted. What is at stake is everything else the learner has, and the
     * free answer is the empty list, which is why an employer cannot see a
     * self-purchased course today.
     *
     * A policy widening this widens it for members of the organisation in the
     * context and for nobody else: the caller pairs the answer with its own
     * membership predicate, so a policy cannot reach another tenant's rows
     * whatever it returns.
     *
     * @return list<string> entitlement source words, e.g. `purchase`, `manual`
     */
    public function visibleSources(OrgVisibilityContext $context): array;
}
