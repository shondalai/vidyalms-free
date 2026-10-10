<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Domain\Assessment\ItemSessionControl;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Quiz;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizPolicy;
use Shondalai\VidyaLMS\Core\Domain\Assessment\ReviewVisibility;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;

/**
 * The quiz settings form, read off a request body. Create and replace take
 * the same shape, so they read it the same way; an omitted optional field
 * takes its documented default rather than its stored value, which is what
 * makes the replace a replace.
 */
final class QuizSettings
{
    private function __construct(
        public readonly string $title,
        public readonly ?int $lessonId,
        public readonly ?string $description,
        public readonly int $passMark,
        public readonly ?int $maxAttempts,
        public readonly ?int $timeLimitSeconds,
        public readonly bool $isFinal,
        public readonly string $showResults,
        public readonly QuizPolicy $policy,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public static function fromBody(array $body): self
    {
        $showResults = Body::nullableString($body, 'show_results', Quiz::SHOW_RESULTS_AFTER_SUBMIT)
            ?? Quiz::SHOW_RESULTS_AFTER_SUBMIT;

        return new self(
            Body::requiredString($body, 'title'),
            Body::nullableInt($body, 'lesson_id'),
            Body::nullableString($body, 'description'),
            Body::nullableInt($body, 'pass_mark', 0) ?? 0,
            Body::nullableInt($body, 'max_attempts'),
            Body::nullableInt($body, 'time_limit_seconds'),
            Body::bool($body, 'is_final', false),
            $showResults,
            // The coarse switch reaches the matrix, because an omitted phase
            // derives from it: show_results stays what every client already
            // reads, and this is the matrix underneath it.
            self::policyFrom(Body::object($body, 'policy'), $showResults),
        );
    }

    /**
     * The quiz-level policy set, read the same way for create and replace.
     *
     * Shape checks only. Whether a behaviour key names a provider this site
     * has is a rule, and rules live in the use case: a handler that answered
     * that would be a second rulebook nobody updates, and the answer depends
     * on what is registered rather than on what was typed.
     *
     * @param array<string, mixed> $policy
     */
    private static function policyFrom(array $policy, string $showResults): QuizPolicy
    {
        $defaults = new QuizPolicy();
        $control = Body::object($policy, 'item_session_control');
        $visibility = Body::object($policy, 'review_visibility');

        return new QuizPolicy(
            behaviour: Body::nullableString($policy, 'behaviour'),
            navigationMode: Body::nullableString($policy, 'navigation_mode', $defaults->navigationMode)
                ?? $defaults->navigationMode,
            submissionMode: Body::nullableString($policy, 'submission_mode', $defaults->submissionMode)
                ?? $defaults->submissionMode,
            shuffleQuestions: Body::bool($policy, 'shuffle_questions', $defaults->shuffleQuestions),
            shuffleOptions: Body::bool($policy, 'shuffle_options', $defaults->shuffleOptions),
            onePerPage: Body::bool($policy, 'one_per_page', $defaults->onePerPage),
            opensAt: self::dateTime($policy, 'opens_at'),
            closesAt: self::dateTime($policy, 'closes_at'),
            expiryPolicy: Body::nullableString($policy, 'expiry_policy', $defaults->expiryPolicy)
                ?? $defaults->expiryPolicy,
            graceSeconds: Body::nullableInt($policy, 'grace_seconds', $defaults->graceSeconds)
                ?? $defaults->graceSeconds,
            autoSubmit: Body::bool($policy, 'auto_submit', $defaults->autoSubmit),
            cooldownAfterFirstSeconds: Body::nullableInt($policy, 'cooldown_after_first_seconds'),
            cooldownAfterLaterSeconds: Body::nullableInt($policy, 'cooldown_after_later_seconds'),
            attemptsCounted: Body::nullableString($policy, 'attempts_counted', $defaults->attemptsCounted)
                ?? $defaults->attemptsCounted,
            attemptAggregation: Body::nullableString($policy, 'attempt_aggregation', $defaults->attemptAggregation)
                ?? $defaults->attemptAggregation,
            attemptAggregationParams: \array_key_exists('attempt_aggregation_params', $policy)
                && \is_array($policy['attempt_aggregation_params'])
                    ? Body::object($policy, 'attempt_aggregation_params')
                    : null,
            negativeMarkingPolicy: Body::nullableString($policy, 'negative_marking_policy'),
            allowQuizNegative: Body::bool($policy, 'allow_quiz_negative', $defaults->allowQuizNegative),
            tryPenalty: Body::number($policy, 'try_penalty', $defaults->tryPenalty),
            itemSessionControl: $control === []
                ? $defaults->itemSessionControl
                : ItemSessionControl::fromArray($control),
            reviewVisibility: $visibility === [] ? null : ReviewVisibility::fromArray($visibility, $showResults),
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function dateTime(array $body, string $key): ?\DateTimeImmutable
    {
        $value = Body::nullableString($body, $key);

        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$key => 'A UTC datetime is required.']);
        }
    }
}
