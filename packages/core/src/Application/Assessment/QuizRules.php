<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Domain\Assessment\ItemSessionControl;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Aggregation\AggregationMethodRegistry;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\NegativeMarking\NegativeMarkingRegistry;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\PolicyResolution;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\QuestionTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\QuestionType\ScoringPolicyAwareInterface;

/**
 * Field rules shared by CreateQuiz and UpdateQuiz.
 */
final class QuizRules
{
    private function __construct()
    {
    }

    public static function validate(
        string $title,
        int $passMark,
        ?int $maxAttempts,
        ?int $timeLimitSeconds,
        string $showResults,
    ): void {
        if (trim($title) === '') {
            throw ValidationException::forField('title', 'A title is required.');
        }

        if ($passMark < 0 || $passMark > 100) {
            throw ValidationException::forField('pass_mark', 'The pass mark must be between 0 and 100.');
        }

        if ($maxAttempts !== null && $maxAttempts < 1) {
            throw ValidationException::forField('max_attempts', 'Max attempts must be at least 1 when limited.');
        }

        if ($timeLimitSeconds !== null && $timeLimitSeconds < 1) {
            throw ValidationException::forField('time_limit_seconds', 'The time limit must be positive when set.');
        }

        $allowed = [Quiz::SHOW_RESULTS_AFTER_SUBMIT, Quiz::SHOW_RESULTS_NEVER];

        if (!\in_array($showResults, $allowed, true)) {
            throw ValidationException::forField('show_results', 'Unknown results visibility.');
        }
    }

    /**
     * The quiz-level policy set, checked against what this site can actually
     * honour and normalised where two settings would otherwise contradict
     * each other.
     *
     * Every provider-keyed member is refused when no registered provider
     * understands it, which is the write-side half of the registry-driven
     * settings form: the form offers only what the site has, and the write
     * refuses anything else rather than storing a key that will silently
     * fall back at every attempt start.
     */
    public static function validatePolicy(
        QuizPolicy $policy,
        AttemptPolicyRegistry $behaviours,
        AggregationMethodRegistry $aggregations,
        NegativeMarkingRegistry $negativeMarking,
        /**
         * What the quiz already stores, when this is an edit.
         *
         * **A write may re-send a provider key it did not change.** The
         * settings form deliberately keeps an unresolvable stored key
         * selected and sends it back, and refusing it turned every field on
         * the screen unsaveable the moment a package was removed: an author
         * could not change the pass mark, the time limit or the title of a
         * quiz whose behaviour no longer resolved, and no copy told them
         * which field was blocking the save. So an unresolvable key is
         * refused only when the request actually moves the setting to it.
         */
        ?QuizPolicy $stored = null,
    ): QuizPolicy {
        self::requireKnownIfMoved('behaviour', $policy->behaviour, $stored?->behaviour, $behaviours->keys(), true);
        self::requireOneOf('navigation_mode', $policy->navigationMode, QuizPolicy::navigationModes());
        self::requireOneOf('submission_mode', $policy->submissionMode, QuizPolicy::submissionModes());
        self::requireOneOf('expiry_policy', $policy->expiryPolicy, QuizPolicy::expiryPolicies());
        self::requireOneOf('attempts_counted', $policy->attemptsCounted, QuizPolicy::attemptsCountedValues());
        self::requireKnownIfMoved(
            'attempt_aggregation',
            $policy->attemptAggregation,
            $stored?->attemptAggregation,
            $aggregations->keys(),
            false,
        );
        self::requireKnownIfMoved(
            'negative_marking_policy',
            $policy->negativeMarkingPolicy,
            $stored?->negativeMarkingPolicy,
            $negativeMarking->keys(),
            true,
        );

        if ($policy->graceSeconds < QuizPolicy::MIN_GRACE_SECONDS) {
            throw ValidationException::forField('grace_seconds', sprintf(
                'The grace period cannot be below %d seconds, which is the clock skew floor.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                QuizPolicy::MIN_GRACE_SECONDS,
            ));
        }

        self::requireNonNegative('cooldown_after_first_seconds', $policy->cooldownAfterFirstSeconds);
        self::requireNonNegative('cooldown_after_later_seconds', $policy->cooldownAfterLaterSeconds);

        if ($policy->tryPenalty < 0.0 || $policy->tryPenalty > 1.0) {
            throw ValidationException::forField('try_penalty', 'The try penalty is a fraction between 0 and 1.');
        }

        if ($policy->itemSessionControl->maxAttempts < 0) {
            throw ValidationException::forField(
                'item_session_control',
                'Tries per item cannot be negative; 0 means unlimited.',
            );
        }

        $opensAt = $policy->opensAt;
        $closesAt = $policy->closesAt;

        if ($opensAt !== null && $closesAt !== null && $closesAt->getTimestamp() <= $opensAt->getTimestamp()) {
            throw ValidationException::forField('closes_at', 'The quiz must close after it opens.');
        }

        return self::normalised($policy);
    }

    /**
     * The two redundancies the design names, normalised rather than stored
     * contradicting each other.
     *
     * Counting one attempt makes the aggregator a no-op by construction, so
     * storing a chosen one beside it would be two settings that read as
     * disagreeing while behaving identically; the neutral default is stored
     * instead, and the authoring UI states the effective rule in one
     * sentence.
     *
     * Simultaneous submission defers all response processing to the end of
     * the part, so the candidate gets exactly one try per item by definition.
     * Storing a higher per-item try count beside it would promise the author
     * retries the mode makes unreachable.
     */
    private static function normalised(QuizPolicy $policy): QuizPolicy
    {
        $aggregation = $policy->attemptsCounted === QuizPolicy::COUNTED_ALL
            ? $policy->attemptAggregation
            : AggregationMethodRegistry::HIGHEST;
        $params = $aggregation === $policy->attemptAggregation ? $policy->attemptAggregationParams : null;
        $control = $policy->itemSessionControl;

        if ($policy->submissionMode === QuizPolicy::SUBMISSION_SIMULTANEOUS && $control->maxAttempts !== 1) {
            $control = new ItemSessionControl(
                1,
                $control->showFeedback,
                $control->allowReview,
                $control->showSolution,
                $control->allowComment,
                $control->allowSkipping,
                $control->validateResponses,
            );
        }

        if ($aggregation === $policy->attemptAggregation && $control === $policy->itemSessionControl) {
            return $policy;
        }

        return new QuizPolicy(
            behaviour: $policy->behaviour,
            navigationMode: $policy->navigationMode,
            submissionMode: $policy->submissionMode,
            shuffleQuestions: $policy->shuffleQuestions,
            shuffleOptions: $policy->shuffleOptions,
            onePerPage: $policy->onePerPage,
            opensAt: $policy->opensAt,
            closesAt: $policy->closesAt,
            expiryPolicy: $policy->expiryPolicy,
            graceSeconds: $policy->graceSeconds,
            autoSubmit: $policy->autoSubmit,
            cooldownAfterFirstSeconds: $policy->cooldownAfterFirstSeconds,
            cooldownAfterLaterSeconds: $policy->cooldownAfterLaterSeconds,
            attemptsCounted: $policy->attemptsCounted,
            attemptAggregation: $aggregation,
            attemptAggregationParams: $params,
            negativeMarkingPolicy: $policy->negativeMarkingPolicy,
            allowQuizNegative: $policy->allowQuizNegative,
            tryPenalty: $policy->tryPenalty,
            itemSessionControl: $control,
            reviewVisibility: $policy->reviewVisibility,
        );
    }

    /**
     * A quiz whose scoreable maximum is 0 while the pass mark is above 0 can
     * never be passed, and refusing it at save time is cheaper than every
     * learner failing silently. A survey-only quiz with a pass mark of 0
     * passes everyone, which is correct.
     */
    public static function requireAttainablePassMark(int $passMark, int $scoreableQuestions): void
    {
        if ($passMark > 0 && $scoreableQuestions === 0) {
            throw ValidationException::forField(
                'pass_mark',
                'This quiz has nothing that counts towards a score, so a pass mark above 0 can never be reached.',
            );
        }
    }

    /**
     * @param list<string> $registered
     */
    /**
     * The provider-key check, skipped when the request leaves the stored key
     * exactly where it was.
     *
     * A write that re-sends a value it did not change is not a request to
     * use that provider; it is the shape of every full-replacement PUT the
     * shipped settings form makes. Refusing it turned an unresolvable stored
     * key into a lock on every other field of the screen.
     *
     * @param list<string> $registered
     */
    private static function requireKnownIfMoved(
        string $field,
        ?string $key,
        ?string $stored,
        array $registered,
        bool $nullable,
    ): void {
        if ($stored !== null && $key === $stored) {
            return;
        }

        self::requireKnown($field, $key, $registered, $nullable);
    }

    /**
     * @param list<string> $registered
     */
    private static function requireKnown(string $field, ?string $key, array $registered, bool $nullable): void
    {
        if ($key === null || $key === '') {
            if (!$nullable) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field, 'A value is required.');
            }

            return;
        }

        if (!\in_array($key, $registered, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, sprintf(
                'No installed package provides "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $key,
            ));
        }
    }

    /**
     * @param list<string> $allowed
     */
    private static function requireOneOf(string $field, string $value, array $allowed): void
    {
        if (!\in_array($value, $allowed, true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, sprintf('Unknown %s.', str_replace('_', ' ', $field)));
        }
    }

    private static function requireNonNegative(string $field, ?int $seconds): void
    {
        if ($seconds !== null && $seconds < 0) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'A duration cannot be negative.');
        }
    }

    /**
     * A question whose resolved policy scores nothing must carry scored = 0.
     *
     * The design states the pairing as a rule of the `none` policy, and
     * nothing enforced it: a scored question naming `none` was authorable and
     * was harmless only by accident, because the totals happen to exclude a
     * notscored result by a different rule. An opinion scale that says it
     * counts is a promise the marking cannot keep, and the author should be
     * told at authoring time rather than after an attempt.
     *
     * @param array<string, mixed> $definition
     */
    public static function requireUnscoredWhenPolicyScoresNothing(
        QuestionTypeInterface $provider,
        array $definition,
        bool $scored,
    ): void {
        if (!$scored || !$provider instanceof ScoringPolicyAwareInterface) {
            return;
        }

        $key = PolicyResolution::keyFor($definition, $provider->legacyPolicy() ?? '', $provider->defaultPolicy());

        if ($key === PolicyResolution::SCORES_NOTHING) {
            throw ValidationException::forField(
                'scored',
                'A question marked with the "none" policy scores nothing, so it must be authored as unscored.',
            );
        }
    }

    /**
     * The lesson must belong to the course and be of type "quiz". Read-only
     * cross-context lookup into the Curriculum tables.
     */
    public static function requireQuizLessonOfCourse(DatabasePort $database, int $courseId, int $lessonId): void
    {
        $lesson = $database->selectOne(
            Query::from('lessons')->select(['id', 'course_id', 'type'])->where('id', '=', $lessonId),
        );

        if ($lesson === null || (int) $lesson['course_id'] !== $courseId) {
            throw ValidationException::forField('lesson_id', 'The lesson must belong to the quiz course.');
        }

        if ($lesson['type'] !== 'quiz') {
            throw ValidationException::forField('lesson_id', 'The lesson must be a quiz lesson.');
        }
    }
}
