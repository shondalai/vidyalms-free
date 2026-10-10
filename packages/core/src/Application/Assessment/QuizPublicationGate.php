<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuestionRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationCheckRegistry;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationContext;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * The registered publication checks, run over a quiz.
 *
 * **In the core because the free build has checks to run and a transition to
 * run them on.** The whole mechanism used to live in the paid package, so a
 * free site published every quiz unchecked: an item marked
 * correct_answers_only with no minimum, a definition that can award a
 * negative mark on a quiz that promised no penalty, an ordering item already
 * in its answer order, "all of the above" inside a shuffled set. Every one
 * of those is over free content.
 *
 * It also had no production caller of any kind. The paid checker was
 * constructed in exactly one place in the whole repository, a test, so even
 * the paid checks the design marks BLOCKS never ran on a real site.
 *
 * A package with slot-, pool- or rubric-aware checks registers them into the
 * same registry and attaches its own facts to the context, so neither
 * package owns the seam.
 */
final class QuizPublicationGate
{
    public function __construct(
        private readonly QuizRepository $quizzes,
        private readonly QuestionRepository $questions,
        private readonly PublicationCheckRegistry $checks,
        /**
         * What a package adds to the context before the checks run, keyed by
         * its own fact key. A package registers one from its kernel-ready
         * listener; the core knows nothing about the shapes.
         *
         * @var list<callable(Quiz): array{string, object}>
         */
        private array $contributors = [],
    ) {
    }

    /**
     * Lets a package attach its own facts to every context this gate builds.
     *
     * @param callable(Quiz): array{string, object} $contributor
     */
    public function contribute(callable $contributor): void
    {
        $this->contributors[] = $contributor;
    }

    /**
     * @return list<PublicationFinding>
     */
    public function findingsFor(Quiz $quiz, ?AttemptPolicySet $policy = null): array
    {
        return $this->checks->runAll($this->contextFor($quiz, $policy));
    }

    /**
     * Refuses the transition when a check blocks it, naming every blocking
     * finding rather than only the first: an author who fixes one and is
     * then told about the next is being made to do the work in the slowest
     * possible order.
     *
     * @return list<PublicationFinding>
     */
    public function requirePublishable(Quiz $quiz, ?AttemptPolicySet $policy = null): array
    {
        $findings = $this->findingsFor($quiz, $policy);
        $blocking = PublicationCheckRegistry::blocking($findings);

        if ($blocking !== []) {
            $errors = [];

            foreach ($blocking as $index => $finding) {
                $errors[$finding->reference() . '.' . $index] = $finding->code;
            }

            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException($errors, 'This quiz cannot be published yet.');
        }

        return $findings;
    }

    /**
     * Every quiz of a course, checked before the course goes live.
     *
     * @return list<PublicationFinding>
     */
    public function requireCoursePublishable(int $courseId): array
    {
        $findings = [];

        foreach ($this->quizzes->forCourse($courseId) as $quiz) {
            foreach ($this->requirePublishable($quiz) as $finding) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    private function contextFor(Quiz $quiz, ?AttemptPolicySet $policy): PublicationContext
    {
        $quizId = (int) $quiz->id;

        $context = PublicationContext::create(
            $quizId,
            $this->questions->forQuiz($quizId),
            $policy ?? AttemptPolicySet::fromQuizPolicy($quiz->behaviourKey(), $quiz->policy),
            [
                'max_attempts' => $quiz->maxAttempts,
                'time_limit_seconds' => $quiz->timeLimitSeconds,
                'pass_mark' => $quiz->passMark,
                'shuffle_options' => $quiz->policy->shuffleOptions,
            ],
        );

        foreach ($this->contributors as $contributor) {
            [$key, $facts] = $contributor($quiz);
            $context->attach($key, $facts);
        }

        return $context;
    }
}
