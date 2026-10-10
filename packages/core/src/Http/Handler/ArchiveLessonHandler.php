<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\ArchiveLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetAuthoringLesson;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * DELETE /manage/lessons/{id}
 *
 * A reversible archive rather than a delete, so it answers the lesson it
 * archived and what that cost, not 204.
 */
final class ArchiveLessonHandler implements HandlerInterface
{
    private const ROUTE = 'manage.lessons.archive';

    public function __construct(
        private readonly ArchiveLesson $archiveLesson,
        private readonly GetAuthoringLesson $getAuthoringLesson,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $lessonId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $lessonId): ApiResponse {
            [, $outcome] = $this->archiveLesson->execute($request->principal, $lessonId);

            return ApiResponse::ok([
                'lesson' => $this->presenters->curriculum->authoringLesson(
                    $this->getAuthoringLesson->execute($request->principal, $lessonId),
                ),
                'outcome' => $this->presenters->curriculum->archiveOutcome($outcome),
            ]);
        });
    }
}
