<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\LessonType;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\LessonType\DefaultContentVersion;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\PresentsFreePreview;

/**
 * Content shape:
 * {
 *   "quiz_id": positive int
 * }
 * The lesson completes when the learner passes the quiz.
 */
final class QuizLessonType implements LessonTypeInterface, PresentsFreePreview
{
    use DefaultContentVersion;

    public function __construct(
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return 'quiz';
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.lesson_type.quiz');
    }

    public function completionMode(): string
    {
        return self::COMPLETION_ON_PASS;
    }

    public function validateContent(array $content): void
    {
        $quizId = $content['quiz_id'] ?? null;

        if (!\is_int($quizId) || $quizId <= 0) {
            throw ValidationException::forField('quiz_id', 'A positive quiz id is required.');
        }
    }

    /**
     * The quiz id is the handle a client starts an attempt with, so a reader
     * without access is given none. The attempt start refuses on its own
     * behalf as well, because a gate visible only in the player is not a
     * gate; withholding the id keeps the two answers consistent rather than
     * relying on the second alone.
     */
    public function presentForLearner(array $content, bool $hasAccess): array
    {
        $quizId = $content['quiz_id'] ?? 0;

        return [
            'quiz_id' => $hasAccess && \is_int($quizId) ? $quizId : 0,
        ];
    }

    /**
     * A quiz preview is its overview: the title, description, question
     * count, pass mark, time limit and attempt allowance, which travel in
     * the lesson's quiz summary for every caller. The questions are served
     * only inside an attempt, and an attempt needs an active enrolment, so
     * the start handle is withheld here for the reason the no-access
     * presentation withholds it. Recorded in
     * 13-learning-flow-and-credentials.md.
     */
    public function presentForPreview(array $content): array
    {
        return $this->presentForLearner($content, false);
    }
}
