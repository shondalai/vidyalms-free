<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\AddAuthoredLesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/modules/{id}/lessons
 */
final class AddLessonHandler implements HandlerInterface
{
    private const ROUTE = 'manage.modules.lessons.add';

    public function __construct(
        private readonly AddAuthoredLesson $addAuthoredLesson,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $moduleId = $request->intPathParam('id');
        $body = $request->body;

        $title = Body::requiredString($body, 'title');
        $type = Body::requiredString($body, 'type');
        $content = Body::object($body, 'content');
        $slug = Body::nullableString($body, 'slug');
        $summary = Body::nullableString($body, 'summary');
        $duration = Body::nullableInt($body, 'duration_seconds');
        $freePreview = Body::bool($body, 'free_preview', false);
        $state = Body::nullableString($body, 'state', Lesson::STATE_PUBLISHED) ?? Lesson::STATE_PUBLISHED;

        return $this->idempotent->run($request, self::ROUTE, function () use (
            $request,
            $moduleId,
            $title,
            $type,
            $content,
            $slug,
            $summary,
            $duration,
            $freePreview,
            $state,
        ): ApiResponse {
            return ApiResponse::created($this->presenters->curriculum->authoringLesson($this->addAuthoredLesson->execute(
                $request->principal,
                $moduleId,
                $title,
                $type,
                $content,
                $slug,
                $summary,
                $duration,
                $freePreview,
                $state,
            )));
        });
    }
}
