<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Access\ListSiteEnrolments;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /admin/enrolments
 */
final class ListAdminEnrolmentsHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListSiteEnrolments $listSiteEnrolments,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $status = $request->queryString('status');
        $search = $request->queryString('q');
        $expiring = $request->queryInt('expiring_within_days', 0);
        $courseId = $request->queryInt('course_id', 0);

        $result = $this->listSiteEnrolments->execute(
            $request->principal,
            $status === '' ? null : $status,
            $expiring === 0 ? null : $expiring,
            $courseId === 0 ? null : $courseId,
            $search === '' ? null : $search,
            $request->queryString('sort', 'expires_at'),
            $request->queryString('order', 'asc'),
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 20),
        );

        return ApiResponse::ok([
            'items' => array_map($this->presenters->access->enrolmentRecord(...), $result->page->items),
            'total' => $result->page->total,
            'page' => $result->page->page,
            'per_page' => $result->page->perPage,
            'facets' => [
                'active' => $result->activeCount,
                'expiring' => $result->expiringCount,
                'suspended' => $result->suspendedCount,
                'expiring_within_days' => $result->expiringWithinDays,
            ],
        ]);
    }
}
