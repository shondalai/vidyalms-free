<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\ReorderQuestions;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * PUT /manage/quizzes/{id}/questions/order
 */
final class ReorderQuestionsHandler implements HandlerInterface
{
    private const ROUTE = 'manage.quizzes.questions.order';

    public function __construct(
        private readonly ReorderQuestions $reorderQuestions,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $quizId = $request->intPathParam('id');
        $questionIds = Body::intList($request->body, 'question_ids');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $quizId, $questionIds): ApiResponse {
            $this->reorderQuestions->execute($request->principal, $quizId, $questionIds);

            return ApiResponse::noContent();
        });
    }
}
