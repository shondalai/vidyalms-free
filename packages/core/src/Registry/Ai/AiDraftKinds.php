<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * The draft kinds the shipped rules are written in terms of.
 *
 * It is a constant list and deliberately **not** an enum, because a draft
 * kind is an open vocabulary: a vendor shipping a task with their own kind
 * and their own acceptor is the acceptance criterion of the whole design, and
 * an enum case inside packages/core is exactly the core edit that criterion
 * forbids. What is closed is the small set of kinds the shipped structural
 * rules name, and that set is here so the rules and the tasks read the same
 * strings.
 *
 * The five artefact kinds are the ones a structural test refuses to see
 * without a declared extension point: a draft that becomes a question, a bank
 * entry, a lesson, a rubric or a course outline is a draft that will be
 * written somewhere, and a task producing one without saying where would have
 * no accept path at all.
 *
 * The display-only kinds are named for the opposite reason: they have no
 * acceptor anywhere in the product by construction, and listing them here is
 * what makes that a decision rather than an omission somebody later fills in.
 *
 * **Every registered free acceptor key appears in exactly one of the two
 * lists, and a structural test asserts it.** Three of the six free acceptor
 * kinds used to appear in neither, which meant the rule that an
 * artefact-producing task must say where it would be written skipped them
 * silently. The two lists are the known-free subset of an open vocabulary and
 * not the universe: a vendor kind is in neither by construction, which is why
 * the structural rules now iterate the registered population and treat these
 * as the shipped answer rather than as the whole question.
 */
final class AiDraftKinds
{
    /** A result that is findings and nothing else. */
    public const FINDINGS = 'findings';

    public const QUESTION = 'question';
    public const BANK_ENTRY = 'bank_entry';
    public const LESSON = 'lesson';
    public const RUBRIC = 'rubric';
    public const COURSE_OUTLINE = 'course_outline';

    /**
     * The three that had a registered free acceptor and were in neither list.
     *
     * A module becomes a module, alt text is written onto a lesson and
     * feedback becomes a comment on an answer, so all three are artefacts by
     * the list's own definition: a draft that will be written somewhere. Being
     * in neither list meant the structural rule that an artefact-producing
     * task must say where it would be written skipped exactly them.
     */
    public const MODULE = 'module';
    public const ALT_TEXT = 'alt_text';
    public const FEEDBACK_TEXT = 'feedback_text';

    /**
     * The kinds that become an artefact, and therefore may not be produced by
     * a task that does not say through which extension point.
     */
    public const ARTEFACT_KINDS = [
        self::QUESTION,
        self::BANK_ENTRY,
        self::LESSON,
        self::RUBRIC,
        self::COURSE_OUTLINE,
        self::MODULE,
        self::ALT_TEXT,
        self::FEEDBACK_TEXT,
    ];

    /**
     * Display-only or field-fill by construction, with no acceptor anywhere.
     *
     * A grouping is confirmed by a person on a screen, question fields are
     * bound into a form the person then saves, a mapping suggestion is
     * registered through the importer's own path, and a narrative is read.
     * None of them is an artefact and none of them is stored as one.
     */
    public const DISPLAY_ONLY_KINDS = [
        self::FINDINGS,
        'grouping_proposal',
        'question_fields',
        'mapping_suggestion',
        'narrative',
    ];

    private function __construct()
    {
    }
}
