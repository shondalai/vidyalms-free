<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Access\SelfEnrol;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * POST /enrolments
 */
final class SelfEnrolHandler implements HandlerInterface
{
    private const ROUTE = 'enrolments.create';

    public function __construct(
        private readonly SelfEnrol $selfEnrol,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->body['course_id'] ?? null;

        if (!\is_int($courseId) || $courseId < 1) {
            throw ApiProblem::validation(['course_id' => 'A positive course id is required.']);
        }

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $courseId): ApiResponse {
            $enrolment = $this->selfEnrol->execute($request->principal, $courseId);

            return ApiResponse::created(Presenter::enrolment($enrolment));
        });
    }
}
