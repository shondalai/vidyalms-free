<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Composition;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Question;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Composition\ComposedQuestion;
use Shondalai\VidyaLMS\Core\Registry\Composition\ComposedQuestionSet;
use Shondalai\VidyaLMS\Core\Registry\Composition\QuizCompositionContext;
use Shondalai\VidyaLMS\Core\Registry\Composition\QuizCompositionInterface;
use Shondalai\VidyaLMS\Core\Registry\Composition\QuizCompositionRegistry;

/**
 * The free build's composition: the quiz's own published questions, in
 * position order, exactly as every quiz has always been served.
 *
 * It claims every quiz at priority zero, so it is the claim of last resort
 * and a quiz can never be left with no composition. A paid composition that
 * resolves a slot tree claims above it and answers first for the quizzes it
 * owns.
 *
 * The one thing it adds is the ordering pass the quiz policy already
 * promised: shuffle_questions was a real column and a resolved policy member
 * that nothing read, so a quiz set to shuffle served its questions in
 * position order anyway. The permutation derives from the attempt seed, so a
 * reload never reorders, and it is suppressed by the same accessibility
 * profile rule that suppresses option shuffle, because a simplified or
 * single-column presentation depends on a stable order.
 */
final class PublishedQuestionsComposition implements QuizCompositionInterface
{
    public function __construct(
        private readonly QuestionRepository $questions,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return QuizCompositionRegistry::PUBLISHED_QUESTIONS;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.composition.quiz_questions');
    }

    public function priority(): int
    {
        return 0;
    }

    public function claims(QuizCompositionContext $context): bool
    {
        return true;
    }

    public function compose(QuizCompositionContext $context): ComposedQuestionSet
    {
        $questions = [];

        foreach ($this->questions->publishedForQuiz($context->quizId) as $question) {
            if ($question->id !== null) {
                // Prefixed because a numeric string key becomes an integer
                // key in PHP, and the seeded shuffle works over identifier
                // strings so that a primitive's channel and a question set
                // permute through the same code.
                $questions['q' . $question->id] = $question;
            }
        }

        $composed = [];

        foreach ($this->order($context, array_keys($questions)) as $id) {
            $question = $questions[$id] ?? null;

            if ($question instanceof Question && $question->id !== null) {
                $composed[] = new ComposedQuestion($question->id);
            }
        }

        return new ComposedQuestionSet($this->key(), $composed);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function order(QuizCompositionContext $context, array $ids): array
    {
        if (!$context->questionShuffle()->enabled) {
            return $ids;
        }

        return $context->seed->shuffle('questions', $ids);
    }
}
