<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\GetQuizAuthoring;
use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateQuiz;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * PUT /manage/quizzes/{id}
 */
final class UpdateQuizHandler implements HandlerInterface
{
    private const ROUTE = 'manage.quizzes.update';

    public function __construct(
        private readonly UpdateQuiz $updateQuiz,
        private readonly GetQuizAuthoring $getQuizAuthoring,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $quizId = $request->intPathParam('id');
        $settings = QuizSettings::fromBody($request->body);

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $quizId, $settings): ApiResponse {
            $this->updateQuiz->execute(
                $request->principal,
                $quizId,
                $settings->title,
                $settings->lessonId,
                $settings->description,
                $settings->passMark,
                $settings->maxAttempts,
                $settings->timeLimitSeconds,
                $settings->isFinal,
                $settings->showResults,
                $settings->policy,
            );

            return ApiResponse::ok($this->presenters->assessment->quizAuthoring(
                $this->getQuizAuthoring->execute($request->principal, $quizId),
            ));
        });
    }
}
