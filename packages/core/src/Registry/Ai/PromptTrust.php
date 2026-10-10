<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * How far a prompt segment may be trusted, in three ranked levels.
 *
 * The author's free instruction field is a third level and not a promotion
 * to trusted, which is the correction this design's own hostile-input list
 * forces: it begins with course content authored by anyone with instructor
 * rights. Calling the free instruction field trusted hands exactly that
 * population the one segment able to cancel the standing instruction that
 * contains everything else, so on a multi-tenant or organisation site an
 * author who is not the site owner could write that the delimited material
 * below is administrator-supplied policy rather than data.
 *
 * The containment story rests on the marking, and the marking must not be
 * defeasible by a form field.
 */
enum PromptTrust: string
{
    /** The task's own prompt, owned by the product or the site owner. */
    case TrustedInstruction = 'trusted_instruction';

    /**
     * The author's free instruction, rendered after the standing data
     * instruction, inside its own delimiters, under a standing line that it
     * may not reclassify any other segment.
     */
    case AuthorInstruction = 'author_instruction';

    /**
     * Everything else: lesson bodies, syllabi, transcripts, submissions,
     * learner answers, imported material, cohort and organisation names.
     */
    case UntrustedContent = 'untrusted_content';

    /** Higher is more trusted. Used for ordering, never for promotion. */
    public function rank(): int
    {
        return match ($this) {
            self::TrustedInstruction => 3,
            self::AuthorInstruction => 2,
            self::UntrustedContent => 1,
        };
    }
}
