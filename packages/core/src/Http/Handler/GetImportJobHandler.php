<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\GetImportJob;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * GET /import/{id}
 *
 * This is the endpoint a running migration is polled through, so it carries
 * the clock: a job whose process died is reported as stalled here rather than
 * leaving the screen polling "running" for ever.
 */
final class GetImportJobHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetImportJob $getImportJob,
        private readonly ClockPort $clock,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok(Presenter::importJob(
            $this->getImportJob->execute($request->principal, $request->intPathParam('id')),
            $this->clock->now(),
        ));
    }
}
