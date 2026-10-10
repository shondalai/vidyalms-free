<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Access\ListMyEnrolments;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /me/enrolments
 */
final class ListMyEnrolmentsHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListMyEnrolments $listMyEnrolments,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->listMyEnrolments->page(
            $request->principal,
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 20),
            array_key_exists('course_id', $request->query) ? $request->queryInt('course_id', 0) : null,
            array_key_exists('organisation_id', $request->query) && $request->queryString('organisation_id') !== '' ? $request->queryInt('organisation_id', -1) : null
        ))->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
