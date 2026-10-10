<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Privacy;

/**
 * Why a record class survived an erasure, from a closed vocabulary.
 *
 * GDPR Article 17(3)(b) defeats erasure where processing is necessary for
 * compliance with a legal obligation and 17(3)(e) where it is necessary for the
 * establishment, exercise or defence of legal claims. A statutory training
 * record is squarely 17(3)(b): OSHA 29 CFR 1910.1030(h)(2) requires
 * bloodborne-pathogens training records for three years, and a site that deletes
 * one on a subject request has broken the employer's obligation rather than
 * honoured the learner's right.
 *
 * **`none` is a real answer and is the one that means the row should have gone.**
 * A receipt that could only say why something was kept would let a contributor
 * keep a row by declining to name a basis, which is how "we retained it" becomes
 * a shrug. A record class retained under `none` is a defect the receipt shows.
 */
final class RetentionBasis
{
    /** Article 17(3)(b): a law requires the record to exist. */
    public const LEGAL_OBLIGATION = 'legal_obligation';

    /** Article 17(3)(e): a claim may still be brought or defended. */
    public const LEGAL_CLAIMS = 'legal_claims';

    /** Article 6(1)(b): the record is the contract's own evidence. */
    public const CONTRACT = 'contract';

    /** No basis was claimed. The row survived and nobody says why. */
    public const NONE = 'none';

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return [self::LEGAL_OBLIGATION, self::LEGAL_CLAIMS, self::CONTRACT, self::NONE];
    }

    /**
     * A supplied word, narrowed to the vocabulary.
     *
     * Anything unrecognised reads as {@see NONE}, which is the honest direction:
     * a basis this build cannot read is a basis it cannot defend, and recording
     * it as though it were a legal obligation would put a claim in a receipt
     * that nothing supports.
     */
    public static function of(?string $basis): string
    {
        return $basis !== null && \in_array($basis, self::all(), true) ? $basis : self::NONE;
    }
}
