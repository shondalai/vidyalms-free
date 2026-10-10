<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\RegradeQuiz;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/quizzes/{id}/regrade
 */
final class RegradeQuizHandler implements HandlerInterface
{
    private const ROUTE = 'manage.quizzes.regrade';

    public function __construct(
        private readonly RegradeQuiz $regradeQuiz,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $quizId = $request->intPathParam('id');
        $regrade = RegradeRequestBody::fromBody($request->body);

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $quizId, $regrade): ApiResponse {
            return ApiResponse::ok($this->presenters->assessment->regradeOutcome(
                $this->regradeQuiz->execute($request->principal, $quizId, $regrade),
            ));
        });
    }
}
