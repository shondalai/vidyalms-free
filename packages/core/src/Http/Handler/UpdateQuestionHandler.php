<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuestion;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * PUT /manage/questions/{id}
 */
final class UpdateQuestionHandler implements HandlerInterface
{
    private const ROUTE = 'manage.questions.update';

    public function __construct(
        private readonly UpdateQuestion $updateQuestion,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $questionId = $request->intPathParam('id');
        $definition = Body::object($request->body, 'definition', true);
        $points = Body::number($request->body, 'points');

        return $this->idempotent->run(
            $request,
            self::ROUTE,
            function () use ($request, $questionId, $definition, $points): ApiResponse {
                return ApiResponse::ok($this->presenters->assessment->question(
                    $this->updateQuestion->execute($request->principal, $questionId, $definition, $points),
                ));
            },
        );
    }
}
