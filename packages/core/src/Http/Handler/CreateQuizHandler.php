<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\CreateQuiz;
use Shondalai\VidyaLMS\Core\Application\Assessment\GetQuizAuthoring;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/courses/{id}/quizzes
 */
final class CreateQuizHandler implements HandlerInterface
{
    private const ROUTE = 'manage.courses.quizzes.create';

    public function __construct(
        private readonly CreateQuiz $createQuiz,
        private readonly GetQuizAuthoring $getQuizAuthoring,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');
        $settings = QuizSettings::fromBody($request->body);

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $courseId, $settings): ApiResponse {
            $quiz = $this->createQuiz->execute(
                $request->principal,
                $courseId,
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

            return ApiResponse::created($this->presenters->assessment->quizAuthoring(
                $this->getQuizAuthoring->execute($request->principal, $quiz->id ?? 0),
            ));
        });
    }
}
