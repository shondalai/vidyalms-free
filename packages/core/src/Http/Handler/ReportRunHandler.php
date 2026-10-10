<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Reporting\GetReportRun;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /manage/report-runs/{id}
 *
 * The poll half of the 202 contract: the run's state, and on completion a
 * short-lived signed URL minted per read rather than stored.
 */
final class ReportRunHandler implements HandlerInterface
{
    public function __construct(private readonly GetReportRun $runs)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $run = $this->runs->execute($request->principal, $request->intPathParam('id'));
        $headers = ['Cache-Control' => 'private, no-store'];

        if (!\in_array($run['state'], ['done', 'empty', 'failed', 'expired'], true)) {
            $headers['Retry-After'] = '5';
        }

        return ApiResponse::ok($run)->withHeaders($headers);
    }
}
