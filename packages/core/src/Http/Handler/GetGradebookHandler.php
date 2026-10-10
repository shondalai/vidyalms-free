<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\GetGradebook;
use Shondalai\VidyaLMS\Core\Application\Platform\GradebookItem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /manage/courses/{id}/gradebook
 */
final class GetGradebookHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetGradebook $getGradebook,
        private readonly StoragePort $storage,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $type = $request->queryString('type');
        $sort = $request->queryString('sort');
        $order = $request->queryString('order');

        $page = $this->getGradebook->execute(
            $request->principal,
            $request->intPathParam('id'),
            $request->queryString('status', GradebookItem::STATUS_PENDING),
            $type === '' ? null : $type,
            $sort === '' ? null : $sort,
            $order === '' ? null : $order,
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 20),
        );

        $storage = $this->storage;

        return ApiResponse::ok([
            'items' => array_map(
                fn (GradebookItem $item): array => $this->presenters->platform->gradebookItem($item, $storage),
                $page->items,
            ),
            'total' => $page->total,
            'page' => $page->page,
            'per_page' => $page->perPage,
            'pending_total' => $page->pendingTotal,
            'graded_total' => $page->gradedTotal,
        ]);
    }
}
