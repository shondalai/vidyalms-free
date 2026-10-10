<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assignments\GradeSubmission;
use Shondalai\VidyaLMS\Core\Application\Progress\GradeOutcomeReader;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /submissions/{id}/grade
 *
 * The response says what the grade did. Grading the last assignment of a
 * course completes the enrolment and issues a certificate through the
 * listeners, and a grader who saw only "graded" would have to go and look.
 */
final class GradeSubmissionHandler implements HandlerInterface
{
    private const ROUTE = 'submissions.grade';

    public function __construct(
        private readonly GradeSubmission $gradeSubmission,
        private readonly GradeOutcomeReader $outcomes,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $submissionId = $request->intPathParam('id');
        $body = $request->body;

        $points = $body['points_awarded'] ?? null;

        if ($points !== null && !\is_int($points) && !\is_float($points)) {
            throw ApiProblem::validation(['points_awarded' => 'A number of points is required.']);
        }
        $decision = Body::nullableString($body, 'decision', 'auto') ?? 'auto';
        $criteria = $body['criteria'] ?? [];
        if (!is_array($criteria)) {
            throw ApiProblem::validation(['criteria' => 'Must be a list of criterion scores.']);
        }

        $feedback = $body['feedback'] ?? null;

        if ($feedback !== null && !\is_string($feedback)) {
            throw ApiProblem::validation(['feedback' => 'Must be a string.']);
        }

        return $this->idempotent->run(
            $request,
            self::ROUTE,
            function () use ($request, $submissionId, $points, $feedback, $decision, $criteria): ApiResponse {
                $submission = $this->gradeSubmission->execute($request->principal, $submissionId, $points === null ? null : (float) $points, $feedback, $decision, $criteria);

                return ApiResponse::ok(Presenter::submission($submission) + [
                    'outcome' => $this->presenters->assessment->gradeOutcome($this->outcomes->forSubmission($submission)),
                ]);
            },
            onReplay: function () use ($request, $submissionId): ApiResponse {
                $submission = $this->gradeSubmission->currentForGrader($request->principal, $submissionId);
                return ApiResponse::ok(Presenter::submission($submission) + [
                    'outcome' => $this->presenters->assessment->gradeOutcome($this->outcomes->forSubmission($submission)),
                ]);
            },
        );
    }
}
