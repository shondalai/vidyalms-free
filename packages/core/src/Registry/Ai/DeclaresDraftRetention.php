<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

use Shondalai\VidyaLMS\Core\Registry\Privacy\RetentionBasis;

/**
 * A task that says how long its drafts should be kept, and under what.
 *
 * AI retention was three constants: ninety days for every draft kind, four
 * hundred for a run with a hundred and eighty day floor, and twenty four hours
 * for an abandoned reservation. None of them named a lawful basis, and no AI
 * record class declared one at all while learning, organisations and the
 * ledger redaction store all did.
 *
 * The ninety-day constant is also the wrong shape for an open vocabulary. A
 * glossary suggestion and a feedback draft derived from a named learner's
 * submission are both drafts and they are not the same personal data, so a
 * vendor draft kind whose payload is a learner's own words could not declare a
 * shorter retention than a suggestion nobody reads.
 *
 * So the task that produced the draft declares it. Absent means the shipped
 * default, which is what every free task means today: an unstated retention is
 * the site's default rather than an unbounded one.
 */
interface DeclaresDraftRetention
{
    /**
     * How many days a draft of this kind should be kept, or null for the
     * site's default. A shorter answer than the default is honoured; a longer
     * one is not, because a task may not extend how long personal data it
     * produced is held beyond what the site decided.
     */
    public function draftRetentionDays(string $draftKind): ?int;

    /**
     * Why a draft of this kind is kept at all, from the closed vocabulary the
     * erasure receipt already reads.
     *
     * {@see RetentionBasis::NONE} is the honest answer for a draft nobody has
     * to justify keeping, and it is the default.
     */
    public function draftRetentionBasis(string $draftKind): string;
}
