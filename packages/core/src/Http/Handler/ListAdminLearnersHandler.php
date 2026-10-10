<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\ListLearners;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/** GET /admin/learners */
final class ListAdminLearnersHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListLearners $listLearners,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $search = $request->queryString('q');
        $page = $this->listLearners->execute(
            $request->principal,
            $search === '' ? null : $search,
            $request->queryString('sort', 'last_active'),
            $request->queryString('order', 'desc'),
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 20),
        );

        return ApiResponse::ok([
            'items' => array_map($this->presenters->platform->adminLearner(...), $page->items),
            'total' => $page->total,
            'page' => $page->page,
            'per_page' => $page->perPage,
        ]);
    }
}
