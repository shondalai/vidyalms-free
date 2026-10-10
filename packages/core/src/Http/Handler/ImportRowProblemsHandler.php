<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\FailedRowsFile;
use Shondalai\VidyaLMS\Core\Application\Platform\ImportRowProblems;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /import/{id}/problems and GET /import/{id}/failed-rows
 *
 * The site console's half of the row report, for the migrations that belong to
 * no organisation. Without it a Moodle or LearnDash load would write row
 * problems that nothing could ever read, which is worse than not writing them:
 * the operator sees a job marked completed_with_problems and has no way to
 * find out which rows the problems were.
 */
final class ImportRowProblemsHandler implements HandlerInterface
{
    public function __construct(
        private readonly ImportRowProblems $problems,
        private readonly bool $download = false,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $jobId = $request->intPathParam('id');

        if ($this->download) {
            $download = $this->problems->failedRows($request->principal, $jobId);

            // The cap travels with the bytes. A client that never opens the
            // body still learns that rows were left behind, which is the
            // half a trailing marker row cannot cover.
            return ApiResponse::binary(
                $download->body,
                'text/csv; charset=UTF-8',
                FailedRowsFile::nameFor($jobId),
                $download->headers(),
                'attachment',
            );
        }

        return ApiResponse::ok(Presenter::importRowProblems($this->problems->page(
            $request->principal,
            $jobId,
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 25),
            $this->filter($request, 'code'),
            $this->filter($request, 'outcome'),
        )));
    }

    private function filter(ApiRequest $request, string $name): ?string
    {
        $value = trim($request->queryString($name));

        return $value === '' ? null : $value;
    }
}
