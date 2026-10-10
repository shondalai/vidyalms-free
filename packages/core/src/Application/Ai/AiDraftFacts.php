<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftRecord;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationContext;

/**
 * The AI facts a draft review attaches to a PublicationContext, under the key
 * `ai`.
 *
 * PublicationContext states plainly that facts a package adds travel in the bag
 * and not in a new member, and that a package attaches one object under its own
 * key before the checks run and narrows it back with instanceof. So the draft
 * review screen assembles a PublicationContext over TRANSIENT Question objects
 * built from the drafts, attaches this under `ai`, and runs the existing
 * publication registry unchanged.
 *
 * The author therefore meets ONE findings vocabulary, warning / acknowledge /
 * blocks, at review time and again at publish time, with no new contract and no
 * second review UI. An AI-specific publication check is an ordinary publication
 * check that returns nothing when no `ai` facts are attached, which is what
 * makes this additive rather than a widening of a shipped context object.
 *
 * **The synthetic negative id convention lives here rather than in a screen.**
 * PublicationFinding carries a nullable questionId and a transient Question is
 * constructed with id null exactly as AddQuestion does, so a check writing
 * questionId would emit null for every draft and a run over twenty drafted items
 * would produce twenty findings none of which names an item. That is the second
 * of the four controls that make the human step real, so each transient question
 * is given the stable synthetic id -{draftId}: Question::withId(int) accepts any
 * int and the two id spaces are disjoint under the negation. The review
 * envelope translates it back.
 *
 * **The honest limitation, carried rather than hidden**: PublicationContext is
 * quiz shaped, so outline, lesson and rubric drafts take their findings from the
 * task result instead. Widening the context would be precisely the core edit
 * this design exists to avoid; a DraftCheckInterface may earn its place when
 * somebody actually needs it, and not speculatively before.
 */
final class AiDraftFacts
{
    /** The bag key this object travels under. */
    public const KEY = 'ai';

    /** @var array<int, list<string>> */
    public readonly array $citationsByDraft;

    /** @var array<int, float> */
    public readonly array $similarityByDraft;

    /** @var array<int, list<string>> */
    public readonly array $critiqueFlagsByDraft;

    /** @var array<int, int> */
    public readonly array $draftIdByQuestionId;

    /**
     * @param array<int, list<string>> $citationsByDraft     draft id to chunk keys
     * @param array<int, float>        $similarityByDraft    draft id to its highest
     *                                                       similarity against the
     *                                                       bank, where a near
     *                                                       duplicate check found
     *                                                       one
     * @param array<int, list<string>> $critiqueFlagsByDraft draft id to the flags a
     *                                                       critique task raised,
     *                                                       which are never a
     *                                                       verdict
     * @param array<int, int>          $draftIdByQuestionId  synthetic question id
     *                                                       to draft id
     */
    private function __construct(
        array $citationsByDraft,
        array $similarityByDraft,
        array $critiqueFlagsByDraft,
        array $draftIdByQuestionId,
    ) {
        $this->citationsByDraft = $citationsByDraft;
        $this->similarityByDraft = $similarityByDraft;
        $this->critiqueFlagsByDraft = $critiqueFlagsByDraft;
        $this->draftIdByQuestionId = $draftIdByQuestionId;
    }

    /**
     * Builds the bag from the drafts a review is over.
     *
     * @param list<AiDraftRecord>      $drafts
     * @param array<int, float>        $similarity
     * @param array<int, list<string>> $critiqueFlags
     */
    public static function forDrafts(array $drafts, array $similarity = [], array $critiqueFlags = []): self
    {
        $citations = [];
        $byQuestionId = [];

        foreach ($drafts as $draft) {
            $citations[$draft->id] = $draft->citations;
            $byQuestionId[$draft->syntheticQuestionId()] = $draft->id;
        }

        return new self($citations, $similarity, $critiqueFlags, $byQuestionId);
    }

    /**
     * Attaches this bag to a PublicationContext through the shipped attach() and
     * facts() pair, changing nothing about the context itself.
     */
    public function attachTo(PublicationContext $context): PublicationContext
    {
        return $context->attach(self::KEY, $this);
    }

    /**
     * Narrows the bag back out of a context, or null when no AI facts were
     * attached.
     *
     * A check calling this and getting null is a check over an ordinary publish,
     * and returning nothing is exactly what it should then do.
     */
    public static function from(PublicationContext $context): ?self
    {
        $facts = $context->facts(self::KEY);

        return $facts instanceof self ? $facts : null;
    }

    /**
     * The draft a finding's questionId names, or null when the finding is over a
     * real question.
     *
     * This is the translation the review envelope performs, and it is here so the
     * envelope and the conformance test read the same implementation.
     */
    public function draftFor(?int $questionId): ?int
    {
        if ($questionId === null) {
            return null;
        }

        return $this->draftIdByQuestionId[$questionId] ?? AiDraftRecord::draftIdFromSyntheticQuestionId($questionId);
    }

    /**
     * @return list<string>
     */
    public function citationsFor(int $draftId): array
    {
        return $this->citationsByDraft[$draftId] ?? [];
    }

    public function similarityFor(int $draftId): ?float
    {
        return $this->similarityByDraft[$draftId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function critiqueFlagsFor(int $draftId): array
    {
        return $this->critiqueFlagsByDraft[$draftId] ?? [];
    }
}
