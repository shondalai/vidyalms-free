<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Ai\AiDraftFacts;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftRecord;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftState;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * The facts bag a draft review attaches to a PublicationContext, and the
 * synthetic id convention that makes a finding over a draft name an item.
 *
 * Both halves exist because of the same failure. PublicationFinding carries a
 * nullable questionId, a transient Question built from a draft is constructed
 * with id null exactly as AddQuestion does, and a check writing questionId would
 * then emit null for every draft: a run over twenty drafted items would produce
 * twenty findings none of which names an item, which is the second of the four
 * controls that make the human step real.
 */
final class AiDraftFactsTest extends TestCase
{
    /**
     * The convention: a draft-built question carries -{draftId}, and the two id
     * spaces are disjoint under the negation.
     */
    public function testADraftMintsANegativeSyntheticQuestionIdThatMapsBack(): void
    {
        $draft = $this->draft(42);

        self::assertSame(-42, $draft->syntheticQuestionId());
        self::assertSame(42, AiDraftRecord::draftIdFromSyntheticQuestionId(-42));
        // A real question id is not a draft, and answering one would file a
        // finding about a live item against a draft nobody wrote.
        self::assertNull(AiDraftRecord::draftIdFromSyntheticQuestionId(42));
        self::assertNull(AiDraftRecord::draftIdFromSyntheticQuestionId(null));
    }

    /**
     * The bag travels under its own key through the shipped attach() and facts()
     * pair, and PublicationContext itself is unchanged.
     */
    public function testTheFactsBagAttachesUnderItsOwnKeyAndNarrowsBackWithInstanceof(): void
    {
        $facts = AiDraftFacts::forDrafts([$this->draft(7), $this->draft(8)]);
        $context = PublicationContext::create(1);

        self::assertNull(AiDraftFacts::from($context), 'A publish with no AI facts sees none.');

        $facts->attachTo($context);

        $narrowed = AiDraftFacts::from($context);
        self::assertInstanceOf(AiDraftFacts::class, $narrowed);
        self::assertSame($facts, $narrowed);
        self::assertSame('ai', AiDraftFacts::KEY);
    }

    /**
     * Every finding produced over a draft-built context resolves to a draft.
     *
     * This is the conformance assertion the design asks for, expressed over the
     * translation the review envelope actually performs.
     */
    public function testEveryFindingOverADraftBuiltContextResolvesToADraft(): void
    {
        $drafts = [$this->draft(11), $this->draft(12)];
        $facts = AiDraftFacts::forDrafts($drafts);

        $findings = [
            PublicationFinding::blocks('duplicate', 'ai.check.duplicate', [], -11),
            PublicationFinding::warning('readability', 'ai.check.readability', [], -12),
        ];

        foreach ($findings as $finding) {
            $draftId = $facts->draftFor($finding->questionId);

            self::assertNotNull($draftId, 'A finding over a draft must name the draft.');
            self::assertContains($draftId, [11, 12]);
        }
    }

    /** The bag answers per draft, and answers emptily for a draft it does not hold. */
    public function testTheBagAnswersCitationsSimilarityAndCritiqueFlagsPerDraft(): void
    {
        $facts = AiDraftFacts::forDrafts(
            [$this->draft(5, ['lesson:88#0', 'lesson:88#1'])],
            [5 => 0.92],
            [5 => ['implausible_distractor']],
        );

        self::assertSame(['lesson:88#0', 'lesson:88#1'], $facts->citationsFor(5));
        self::assertSame(0.92, $facts->similarityFor(5));
        self::assertSame(['implausible_distractor'], $facts->critiqueFlagsFor(5));

        self::assertSame([], $facts->citationsFor(6));
        self::assertNull($facts->similarityFor(6));
        self::assertSame([], $facts->critiqueFlagsFor(6));
    }

    /**
     * @param list<string> $citations
     */
    private function draft(int $id, array $citations = []): AiDraftRecord
    {
        return new AiDraftRecord(
            $id,
            1,
            'question',
            'question_type',
            'multiple_choice',
            'quiz',
            9,
            3,
            null,
            0,
            ['prompt' => 'Which leaf makes tea?'],
            1,
            null,
            1,
            $citations,
            1,
            null,
            null,
            AiDraftState::Generated,
            0,
            0,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            new \DateTimeImmutable('2026-09-01 00:00:00'),
        );
    }
}
