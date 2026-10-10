<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\RemoveQuestion;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * DELETE /manage/questions/{id}
 */
final class RemoveQuestionHandler implements HandlerInterface
{
    private const ROUTE = 'manage.questions.remove';

    public function __construct(
        private readonly RemoveQuestion $removeQuestion,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $questionId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $questionId): ApiResponse {
            $this->removeQuestion->execute($request->principal, $questionId);

            return ApiResponse::noContent();
        });
    }
}
