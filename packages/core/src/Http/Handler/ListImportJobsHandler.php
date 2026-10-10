<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\ListImportJobs;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * GET /import
 */
final class ListImportJobsHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListImportJobs $listImportJobs,
        private readonly ClockPort $clock,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $page = $this->listImportJobs->execute(
            $request->principal,
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 20),
        );
        $now = $this->clock->now();

        return ApiResponse::ok([
            'items' => array_map(
                static fn (ImportJob $job): array => Presenter::importJob($job, $now),
                $page->items,
            ),
            'total' => $page->total,
            'page' => $page->page,
            'per_page' => $page->perPage,
        ]);
    }
}
