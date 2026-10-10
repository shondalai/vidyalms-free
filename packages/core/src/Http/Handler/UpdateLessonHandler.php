<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetAuthoringLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateLesson;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * PATCH /manage/lessons/{id}
 */
final class UpdateLessonHandler implements HandlerInterface
{
    private const ROUTE = 'manage.lessons.update';

    public function __construct(
        private readonly UpdateLesson $updateLesson,
        private readonly GetAuthoringLesson $getAuthoringLesson,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $lessonId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $lessonId): ApiResponse {
            $this->updateLesson->execute($request->principal, $lessonId, $request->body);

            return ApiResponse::ok(
                $this->presenters->curriculum->authoringLesson($this->getAuthoringLesson->reload($lessonId)),
            );
        });
    }
}
