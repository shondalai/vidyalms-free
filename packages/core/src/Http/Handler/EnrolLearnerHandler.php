<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Access\EnrolLearnerManually;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/courses/{id}/learners
 */
final class EnrolLearnerHandler implements HandlerInterface
{
    private const ROUTE = 'manage.courses.learners.enrol';

    public function __construct(
        private readonly EnrolLearnerManually $enrolLearnerManually,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');
        $userId = Body::requiredInt($request->body, 'user_id');
        $startsAt = Body::nullableString($request->body, 'starts_at');
        $expiresAt = Body::nullableString($request->body, 'expires_at');

        return $this->idempotent->run($request, self::ROUTE, function () use (
            $request,
            $courseId,
            $userId,
            $startsAt,
            $expiresAt,
        ): ApiResponse {
            return ApiResponse::created($this->presenters->access->accessGrant($this->enrolLearnerManually->execute(
                $request->principal,
                $courseId,
                $userId,
                $startsAt,
                $expiresAt,
            )));
        });
    }
}
