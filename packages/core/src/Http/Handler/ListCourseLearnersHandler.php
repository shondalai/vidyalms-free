<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Access\ListCourseLearners;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /manage/courses/{id}/learners
 */
final class ListCourseLearnersHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListCourseLearners $listCourseLearners,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $status = $request->queryString('status');
        $search = $request->queryString('q');

        $page = $this->listCourseLearners->execute(
            $request->principal,
            $request->intPathParam('id'),
            $status === '' ? null : $status,
            $search === '' ? null : $search,
            $request->queryString('sort', 'name'),
            $request->queryString('order', 'asc'),
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 20),
        );

        return ApiResponse::ok([
            'items' => array_map($this->presenters->access->enrolmentRecord(...), $page->items),
            'total' => $page->total,
            'page' => $page->page,
            'per_page' => $page->perPage,
        ]);
    }
}
