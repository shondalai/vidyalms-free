<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\GradeAnswerManually;
use Shondalai\VidyaLMS\Core\Application\Assessment\ManualGradingStatus;
use Shondalai\VidyaLMS\Core\Application\Progress\GradeOutcomeReader;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /attempts/{id}/answers/{question_id}/grade
 *
 * The response says what the grade did, read back after the listeners ran:
 * whether the attempt is finalised, how many answers still wait, and whether
 * the lesson or the whole enrolment completed on the strength of it.
 */
final class GradeAnswerHandler implements HandlerInterface
{
    private const ROUTE = 'attempts.answers.grade';

    public function __construct(
        private readonly GradeAnswerManually $gradeAnswer,
        private readonly ManualGradingStatus $gradingStatus,
        private readonly GradeOutcomeReader $outcomes,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $attemptId = $request->intPathParam('id');
        $questionId = $request->intPathParam('question_id');
        // **Exactly one of points_awarded and criteria**, which is what the
        // contract says and what the shipped gradebook queue already sends.
        // Reading points_awarded with no default threw 422 whenever the key
        // was absent, so a rubric-marked answer could never be closed and
        // the attempt stayed submitted with passed null forever.
        [$criteria, $comments] = self::rubricSelections($request->body);
        $points = \array_key_exists('points_awarded', $request->body) || $criteria === []
            ? Body::number($request->body, 'points_awarded')
            : null;
        $feedback = Body::nullableString($request->body, 'feedback');
        // Optional, and absent means the whole item, which is what every type
        // with no parts grades as. Carrying it on the body rather than in a
        // path segment keeps one route for both, so a composite does not need
        // a second endpoint and an existing client keeps working.
        $partId = Body::nullableString($request->body, 'part_id');

        return $this->idempotent->run($request, self::ROUTE, function () use (
            $request,
            $attemptId,
            $questionId,
            $points,
            $feedback,
            $partId,
            $criteria,
            $comments,
        ): ApiResponse {
            $attempt = $this->gradeAnswer->execute(
                $request->principal,
                $attemptId,
                $questionId,
                $points,
                $feedback,
                $partId,
                $criteria,
                $comments,
            );
            $outcome = $this->outcomes->of($attempt->enrolmentId, $this->gradingStatus->lessonIdOf($attempt));

            return ApiResponse::ok([
                'attempt' => $this->presenters->assessment->attemptDetail(
                    $attempt,
                    $this->gradingStatus->expiresAtOf($attempt),
                ),
                'outcome' => $this->presenters->assessment->gradeOutcome($outcome) + [
                    'attempt_finalised' => $attempt->status === Attempt::STATUS_GRADED,
                    'pending_manual_count' => $this->gradingStatus->pendingCountFor($attempt),
                ],
            ]);
        }, onReplay: function () use ($request, $attemptId): ApiResponse {
            $attempt = $this->gradeAnswer->currentForGrader($request->principal, $attemptId);
            $outcome = $this->outcomes->of($attempt->enrolmentId, $this->gradingStatus->lessonIdOf($attempt));

            return ApiResponse::ok([
                'attempt' => $this->presenters->assessment->attemptDetail(
                    $attempt,
                    $this->gradingStatus->expiresAtOf($attempt),
                ),
                'outcome' => $this->presenters->assessment->gradeOutcome($outcome) + [
                    'attempt_finalised' => $attempt->status === Attempt::STATUS_GRADED,
                    'pending_manual_count' => $this->gradingStatus->pendingCountFor($attempt),
                ],
            ]);
        });
    }

    /**
     * The rater's per-criterion selections, as (criterion id, level id) and
     * (criterion id, comment).
     *
     * The level id rather than the points, because the level is what makes
     * analytic feedback, rater agreement analysis and a safe rubric revision
     * possible: two raters who both awarded three marks may have chosen
     * different levels for different reasons.
     *
     * @param array<string, mixed> $body
     *
     * @return array{array<int, int>, array<int, string>}
     */
    private static function rubricSelections(array $body): array
    {
        $criteria = $body['criteria'] ?? null;

        if (!\is_array($criteria)) {
            return [[], []];
        }

        $levels = [];
        $comments = [];

        foreach ($criteria as $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            $criterionId = $entry['criterion_id'] ?? null;
            $levelId = $entry['level_id'] ?? null;

            if (!\is_int($criterionId) || !\is_int($levelId)) {
                continue;
            }

            $levels[$criterionId] = $levelId;
            $comment = $entry['comment'] ?? null;

            if (\is_string($comment) && $comment !== '') {
                $comments[$criterionId] = $comment;
            }
        }

        return [$levels, $comments];
    }
}
