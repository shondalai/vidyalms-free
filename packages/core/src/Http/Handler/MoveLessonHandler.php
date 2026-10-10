<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetAuthoringLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\MoveLesson;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/lessons/{id}/move
 */
final class MoveLessonHandler implements HandlerInterface
{
    private const ROUTE = 'manage.lessons.move';

    public function __construct(
        private readonly MoveLesson $moveLesson,
        private readonly GetAuthoringLesson $getAuthoringLesson,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $lessonId = $request->intPathParam('id');
        $moduleId = Body::requiredInt($request->body, 'module_id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $lessonId, $moduleId): ApiResponse {
            $this->moveLesson->execute($request->principal, $lessonId, $moduleId);

            return ApiResponse::ok(
                $this->presenters->curriculum->authoringLesson($this->getAuthoringLesson->reload($lessonId)),
            );
        });
    }
}
