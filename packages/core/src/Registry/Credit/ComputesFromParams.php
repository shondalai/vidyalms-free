<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Credit;

/**
 * A scheme whose amount depends on more than the minutes.
 *
 * The shipped `amountFor(int $contactMinutes): string` is the right signature
 * for the free scheme and for IACET, whose arithmetic is a division. It is
 * not enough for NASBA, whose increment after the first credit is one-fifth
 * or one-half at the sponsor's election, or for ACCME, whose points are
 * authored rather than derived. Adding a parameter to the shipped interface
 * would break every implementation, so this is a capability probed with
 * instanceof, exactly as the design requires of every optional behaviour.
 *
 * It is declared here rather than in the paid package because a capability
 * needs a call site, and its only possible call site is the free code that
 * computes a credit row at issue. Declared in the paid package, the core
 * could not probe it without making that package a dependency of the core,
 * which inverts the edition rule; so the arithmetic never ran, and a sponsor
 * who elected fifth increments got the half-credit default with the elected
 * parameters stored beside it and consulted by nothing.
 *
 * **Default when absent: amountFor() with the params ignored.** A caller that
 * does not probe therefore gets the scheme's documented default increment
 * rather than an error, which is why NASBA's amountFor() answers the
 * one-half form the specification itself defaults to: a site that never
 * elects an increment gets the conservative one.
 *
 * The params the call site passes carry the authored document plus
 * `contact_minutes`, so a scheme reads the minutes through the same bag as
 * everything else it was told.
 */
interface ComputesFromParams
{
    /**
     * @param array<string, mixed> $params the scheme's own stored parameters, plus contact_minutes
     */
    public function amountFromParams(array $params): string;
}
