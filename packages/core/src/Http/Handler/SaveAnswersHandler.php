<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\SaveAnswers;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Answer\AnswerValidity;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * PUT /attempts/{id}/answers
 *
 * Body: {"answers": {"<question id>": <answer>}}, where an answer is either
 * the bare value a shipped client still sends or the envelope carrying
 * ui_state, raw_input, presentation_form and certainty. Answer values are
 * opaque to the handler; the question type providers interpret them.
 *
 * It answers 200 with a per-question validity report rather than 204,
 * because an invalid answer is stored, not refused. Refusing the save makes
 * the "attempted" answered status unreachable and wedges the whole attempt:
 * the runner posts the entire answer map on every autosave, so one rejected
 * key means nothing else the learner writes is ever stored either.
 */
final class SaveAnswersHandler implements HandlerInterface
{
    private const ROUTE = 'attempts.answers';

    public function __construct(
        private readonly SaveAnswers $saveAnswers,
        private readonly IdempotentExecution $idempotent,
        private readonly \Shondalai\VidyaLMS\Core\Application\Assessment\GetAttemptForLearner $current,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $attemptId = $request->intPathParam('id');
        $answers = $this->answersFrom($request);

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $attemptId, $answers): ApiResponse {
            $report = $this->saveAnswers->execute($request->principal, $attemptId, $answers);

            return ApiResponse::ok(['questions' => $this->reportShape($report)]);
        }, onReplay: function (array $stored) use ($request, $attemptId): ApiResponse {
            $this->current->execute($request->principal, $attemptId);
            return ApiResponse::ok($stored);
        });
    }

    /**
     * One entry per saved question: whether it satisfies the interaction's
     * own constraints, and the typed errors the error summary links to.
     *
     * @param array<int, AnswerValidity> $report
     *
     * @return list<array<string, mixed>>
     */
    private function reportShape(array $report): array
    {
        $shape = [];

        foreach ($report as $questionId => $validity) {
            $shape[] = [
                'id' => $questionId,
                'valid' => $validity->isValid(),
                'answered_status' => $validity->answeredStatus()->value,
                'errors' => $validity->toArray(),
            ];
        }

        return $shape;
    }

    /**
     * @return array<int, mixed>
     */
    private function answersFrom(ApiRequest $request): array
    {
        $raw = $request->body['answers'] ?? null;

        if (!\is_array($raw)) {
            throw ApiProblem::validation(['answers' => 'An object keyed by question id is required.']);
        }

        $answers = [];

        foreach ($raw as $questionId => $answer) {
            if (!\is_int($questionId) && preg_match('/^[1-9][0-9]*$/', (string) $questionId) !== 1) {
                throw ApiProblem::validation(['answers' => 'Question ids must be positive integers.']);
            }

            $answers[(int) $questionId] = $answer;
        }

        return $answers;
    }
}
