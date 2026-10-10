<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodRegistry;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\NegativeMarking\NegativeMarkingRegistry;

/**
 * Full-state update of a quiz. The course binding never changes; move a quiz
 * by recreating it on the other course.
 */
final class UpdateQuiz
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly DatabasePort $database,
        private readonly CourseAccess $access,
        private readonly AttemptPolicyRegistry $behaviours,
        private readonly AggregationMethodRegistry $aggregations,
        private readonly NegativeMarkingRegistry $negativeMarking,
        private readonly ?QuestionRepository $questions = null,
    ) {
    }

    public function execute(
        Principal $principal,
        int $quizId,
        string $title,
        ?int $lessonId = null,
        ?string $description = null,
        int $passMark = 0,
        ?int $maxAttempts = null,
        ?int $timeLimitSeconds = null,
        bool $isFinal = false,
        string $showResults = Quiz::SHOW_RESULTS_AFTER_SUBMIT,
        ?QuizPolicy $policy = null,
    ): Quiz {
        $this->access->requireSignedIn($principal);

        $quiz = $this->quizzes->byId($quizId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Quiz', $quizId);

        $this->access->require($principal, $quiz->courseId, Capability::TEACH);

        QuizRules::validate($title, $passMark, $maxAttempts, $timeLimitSeconds, $showResults);
        QuizRules::requireAttainablePassMark($passMark, $this->scoreableQuestionCount($quizId));

        $resolved = QuizRules::validatePolicy(
            $policy ?? new QuizPolicy(),
            $this->behaviours,
            $this->aggregations,
            $this->negativeMarking,
            // What the quiz already stores, so a full-replacement save that
            // re-sends an unresolvable key it did not change is not refused.
            // Without this, removing a package locked every field of the
            // settings screen for every quiz that named one of its keys.
            $quiz->policy,
        );

        if ($lessonId !== null) {
            QuizRules::requireQuizLessonOfCourse($this->database, $quiz->courseId, $lessonId);
        }

        $updated = new Quiz(
            id: $quiz->id,
            courseId: $quiz->courseId,
            lessonId: $lessonId,
            title: trim($title),
            description: $description,
            passMark: $passMark,
            maxAttempts: $maxAttempts,
            timeLimitSeconds: $timeLimitSeconds,
            isFinal: $isFinal,
            showResults: $showResults,
            policy: $resolved,
        );

        $this->quizzes->save($updated);

        return $updated;
    }

    /**
     * How many published questions of the quiz count towards a score.
     *
     * A quiz with none of them and a pass mark above 0 can never be passed,
     * which is a hard field error rather than a publication warning:
     * refusing it at save time is cheaper than every learner failing
     * silently. A quiz with no questions at all is exempt, because that is
     * every quiz on the way to being authored.
     */
    private function scoreableQuestionCount(int $quizId): int
    {
        if ($this->questions === null) {
            return 1;
        }

        $published = $this->questions->publishedForQuiz($quizId);

        if ($published === []) {
            return 1;
        }

        $scoreable = 0;

        foreach ($published as $question) {
            if ($question->scored && $question->points > 0.0) {
                ++$scoreable;
            }
        }

        return $scoreable;
    }
}
