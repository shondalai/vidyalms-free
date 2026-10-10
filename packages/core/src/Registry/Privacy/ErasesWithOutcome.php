<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Privacy;

/**
 * The contributor can answer an erasure with a typed receipt rather than with
 * three count maps and a list of sentences.
 *
 * **It is a capability interface over the shipped contract, never a changed
 * signature.** {@see PrivacyContributorInterface::eraseFor()} returns an array on
 * the published contract, and narrowing its return type would break every
 * shipped and third-party contributor at once. A provider that implements this
 * makes `eraseFor()` a one-line delegation to {@see EraseOutcome::toArray()}, so
 * the array a caller already reads keeps its four keys and gains the two it
 * could not carry. There is therefore one erasure routine and two shapes of its
 * one answer, rather than two routines that can disagree about what they
 * deleted.
 *
 * Default when absent: the caller reads the array and the receipt says nothing
 * about a retention basis or about the rows left alone because they belong to
 * another organisation. That is the state the shipped build was in and it is why
 * this exists: a receipt that cannot name a basis makes retention a shrug, and a
 * receipt that cannot count what it did not touch asserts the tenancy bound
 * instead of showing it.
 */
interface ErasesWithOutcome
{
    /** The same erasure, answered as a receipt. */
    public function eraseWithOutcome(OrgPrivacyContext $context): EraseOutcome;
}
