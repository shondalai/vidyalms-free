<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\SiteOverview;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /admin/dashboard
 */
final class GetAdminDashboardHandler implements HandlerInterface
{
    public function __construct(
        private readonly SiteOverview $siteOverview,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->presenters->platform->siteOverview($this->siteOverview->execute(
            $request->principal,
            $request->queryInt('waiting_days', 3),
            $request->queryInt('expiring_days', 14),
            $request->queryInt('period_days', 30),
        )));
    }
}
