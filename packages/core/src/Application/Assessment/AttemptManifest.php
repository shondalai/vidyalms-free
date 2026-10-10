<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptQuestion;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptQuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Grading\AnsweredStatus;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ChoiceSequence;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\Seed;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\ShufflePolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionDefinitionStore;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\StoredQuestionDefinition;
use Shondalai\VidyaLMS\Core\Port\I18n\FixedLocale;
use Shondalai\VidyaLMS\Core\Port\I18n\LocalePort;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;
use Shondalai\VidyaLMS\Core\Registry\Composition\ComposedQuestion;
use Shondalai\VidyaLMS\Core\Registry\Composition\ComposedQuestionSet;
use Shondalai\VidyaLMS\Core\Registry\Context\PresentationContext;
use Shondalai\VidyaLMS\Core\Registry\Interaction\InteractionPrimitiveRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PrimitiveComposingInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;

/**
 * Freezes the served question set onto an attempt, and reads it back.
 *
 * The freeze happens once, at StartAttempt, and everything after it reads
 * what was frozen. That is what closes three holes at once: an instructor
 * editing a definition mid-attempt no longer rewrites how an in-flight
 * attempt is graded, a question published mid-attempt is no longer graded
 * although the learner never saw it, and a regrade finally has a source that
 * is neither the learner projection (which carries no correctness by
 * construction) nor the live row (which a lazy upgrade can move).
 *
 * An attempt that started before the manifest existed has none, and it must
 * keep working. Those read through the same value object, built from the
 * live rows, so no caller has to know which source it got. The value object
 * says which it was.
 */
final class AttemptManifest
{
    public function __construct(
        private readonly AttemptQuestionRepository $attemptQuestions,
        private readonly QuestionDefinitionStore $definitions,
        private readonly QuestionRepository $questions,
        private readonly QuestionTypes $types,
        private readonly InteractionPrimitiveRegistry $primitives,
        // The request locale, so a presentation context carries the site's
        // language rather than the hardcoded default that made every
        // locale-sensitive provider method unimplementable.
        private readonly LocalePort $locale = new FixedLocale(),
    ) {
    }

    private function locale(): string
    {
        return $this->locale->current();
    }

    /**
     * Writes the manifest for a freshly started attempt: the set the
     * composition served, in the order it served it, with the presentation
     * each learner actually gets.
     *
     * The composition decides which questions and in what order; this
     * freezes what it decided, applies whatever the composition overrode
     * (points and scored travel on the slot, because QTI puts weight on the
     * item reference rather than on the item) and records the selection so a
     * draw can be reconstructed on appeal.
     *
     * @return list<ServedQuestion>
     */
    public function freeze(
        Attempt $attempt,
        AttemptPolicySet $policy,
        Seed $seed,
        ComposedQuestionSet $composition,
    ): array {
        $attemptId = $attempt->id;

        if ($attemptId === null) {
            return [];
        }

        $rows = [];
        $served = [];
        $position = 0;
        $screen = 0;
        $previousStimulus = null;
        $questions = $this->questions->byIds(array_map(
            static fn (ComposedQuestion $composed): int => $composed->questionId,
            $composition->questions,
        ));

        foreach ($composition->questions as $composed) {
            $question = $questions[$composed->questionId] ?? null;

            if ($question === null || $question->id === null) {
                continue;
            }

            ++$position;

            // Consecutive questions sharing a stimulus form one screen, so a
            // reading passage with six questions renders once rather than
            // six times. A question with no stimulus is always its own
            // screen.
            if ($question->stimulusId === null || $question->stimulusId !== $previousStimulus) {
                ++$screen;
            }

            $previousStimulus = $question->stimulusId;

            $resolved = $this->types->forQuestion($question);
            $sequence = $this->sequenceFor($question, $resolved->provider, $policy, $seed);
            $context = PresentationContext::create(
                $seed,
                $sequence,
                $this->locale(),
                $policy->accessibilityProfile()->identifier(),
            );
            $presented = $resolved->present($question->definition, $context, $question->responseShape);

            $stored = StoredQuestionDefinition::of(
                $question->type,
                $question->definitionVersion,
                $question->definition,
            );
            $this->definitions->put($stored);

            $row = new AttemptQuestion(
                id: null,
                attemptId: $attemptId,
                position: $position,
                screen: $screen,
                questionId: $question->id,
                questionVersionId: $composed->questionVersionId,
                points: $composed->points ?? $question->points,
                scored: $composed->scored ?? $question->scored,
                presented: $presented,
                choiceSequence: $sequence,
                definitionHash: $stored->hash,
                answeredStatus: AnsweredStatus::Presented,
                variant: $composed->selection === null ? null : ['selection' => $composed->selection],
                policyKey: $composed->policyKey,
                policyParams: $composed->policyParams,
            );

            $rows[] = $row;
            $served[] = $this->servedFrom($row, $stored, $question, true);
        }

        $this->attemptQuestions->freeze($attemptId, $rows);

        return $served;
    }

    /**
     * The served set of an attempt, frozen when there is a manifest and
     * derived from the live rows when there is not.
     *
     * @return list<ServedQuestion>
     */
    public function forAttempt(Attempt $attempt): array
    {
        $attemptId = $attempt->id;

        if ($attemptId === null) {
            return [];
        }

        $rows = $this->attemptQuestions->forAttempt($attemptId);

        if ($rows === []) {
            return $this->fallbackFor($attempt);
        }

        $definitions = $this->definitions->byHashes(array_map(
            static fn (AttemptQuestion $row): string => $row->definitionHash,
            $rows,
        ));

        // By id rather than by quiz: a composition may serve a question the
        // quiz does not own, and a served question resolved by quiz would
        // then read as a question whose row had vanished.
        $questions = $this->questions->byIds(array_map(
            static fn (AttemptQuestion $row): int => $row->questionId,
            $rows,
        ));

        $served = [];

        foreach ($rows as $row) {
            $question = $questions[$row->questionId] ?? null;
            $stored = $definitions[$row->definitionHash] ?? null;

            if ($stored === null && $question === null) {
                // Neither the frozen definition nor the row survives. The
                // manifest still names what was served, so the item is
                // reported rather than dropped.
                continue;
            }

            // A missing store row is a registry miss, not a licence to grade
            // against the live row. Content addressing exists precisely
            // because the live questions row can have been rewritten since,
            // so rebuilding the entry from it would re-mark a graded attempt
            // against whatever the author has written since, silently, while
            // still reporting itself as frozen. The item is served with the
            // live row's shape for display and marked lost so nothing grades
            // it.
            $definitionLost = $stored === null;
            $stored ??= StoredQuestionDefinition::of(
                $question->type,
                $question->definitionVersion,
                $question->definition,
            );

            $served[] = $this->servedFrom($row, $stored, $question, true, $definitionLost);
        }

        return $served;
    }

    /**
     * The set an attempt that predates the manifest reads, built from the
     * live published rows exactly as grading used to.
     *
     * @return list<ServedQuestion>
     */
    private function fallbackFor(Attempt $attempt): array
    {
        $served = [];
        $position = 0;
        $screen = 0;
        $previousStimulus = null;

        foreach ($this->questions->publishedForQuiz($attempt->quizId) as $question) {
            if ($question->id === null) {
                continue;
            }

            ++$position;

            if ($question->stimulusId === null || $question->stimulusId !== $previousStimulus) {
                ++$screen;
            }

            $previousStimulus = $question->stimulusId;

            $resolved = $this->types->forQuestion($question);
            $context = PresentationContext::create(
                Seed::forAttempt($attempt->id ?? 0),
                locale: $this->locale(),
                accessibilityProfile: $attempt->policy()->accessibilityProfile()->identifier(),
            );

            $served[] = new ServedQuestion(
                questionId: $question->id,
                typeKey: $question->type,
                definitionVersion: $question->definitionVersion,
                position: $position,
                screen: $screen,
                points: $question->points,
                scored: $question->scored,
                definition: $question->definition,
                presented: $resolved->present($question->definition, $context, $question->responseShape),
                choiceSequence: ChoiceSequence::empty(),
                definitionHash: '',
                answeredStatus: AnsweredStatus::Presented,
                responseShape: $question->responseShape,
                externalScored: $question->externalScored,
                stimulusId: $question->stimulusId,
                frozen: false,
            );
        }

        return $served;
    }

    private function servedFrom(
        AttemptQuestion $row,
        StoredQuestionDefinition $stored,
        ?Question $question,
        bool $frozen,
        bool $definitionLost = false,
    ): ServedQuestion {
        return new ServedQuestion(
            questionId: $row->questionId,
            typeKey: $stored->type,
            definitionVersion: $stored->definitionVersion,
            position: $row->position,
            screen: $row->screen,
            points: $row->points,
            scored: $row->scored,
            definition: $stored->definition,
            presented: $row->presented,
            choiceSequence: $row->choiceSequence,
            definitionHash: $row->definitionHash,
            answeredStatus: $row->answeredStatus,
            responseShape: $question?->responseShape,
            externalScored: $question?->externalScored,
            stimulusId: $question?->stimulusId,
            frozen: $frozen,
            definitionLost: $definitionLost,
            policyKey: $row->policyKey,
            policyParams: $row->policyParams,
        );
    }

    /**
     * Shuffle reaches the interaction primitive, never the question type.
     * Core resolves the quiz policy and the learner profile into a
     * ShufflePolicy, calls the primitive, and the per-choice fixed flag is
     * honoured there once for every type that uses that primitive.
     */
    private function sequenceFor(
        Question $question,
        ?QuestionTypeInterface $provider,
        AttemptPolicySet $policy,
        Seed $seed,
    ): ChoiceSequence {
        if ($provider === null) {
            return ChoiceSequence::empty();
        }

        if (!$provider instanceof PrimitiveComposingInterface) {
            return ChoiceSequence::empty();
        }

        $primitive = $this->primitives->resolve($provider->interactionPrimitive());

        if ($primitive === null) {
            return ChoiceSequence::empty();
        }

        $profile = $policy->accessibilityProfile();
        $context = PresentationContext::create($seed, ChoiceSequence::empty(), $this->locale(), $profile->identifier());
        $wantsShuffle = $policy->shufflesOptions() || $provider->shufflesByDefault($question->definition, $context);

        // The learner's frozen profile decides the suppression, so the
        // profile-suppressed branch of ShufflePolicy is reachable rather than
        // dead: a simplified layout or a read-aloud presentation both depend
        // on a stable order.
        return $primitive->presentationSequence(
            $question->definition,
            $seed,
            ShufflePolicy::resolve($wantsShuffle, $profile->suppressesShuffle()),
        );
    }
}
