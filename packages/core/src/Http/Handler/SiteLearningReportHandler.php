<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\GetSiteLearningReport;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

final class SiteLearningReportHandler implements HandlerInterface
{
    public function __construct(private readonly GetSiteLearningReport $report)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->report->execute(
            $request->principal,
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 25),
            $request->queryString('search'),
            $request->queryString('state') ?: null
        ))->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
