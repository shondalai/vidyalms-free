<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\ListInstructors;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /manage/courses/{id}/instructors
 */
final class ListInstructorsHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListInstructors $listInstructors,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok([
            'items' => array_map(
                $this->presenters->curriculum->instructor(...),
                $this->listInstructors->execute($request->principal, $request->intPathParam('id')),
            ),
        ]);
    }
}
