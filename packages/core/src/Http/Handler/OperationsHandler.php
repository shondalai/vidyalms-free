<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\InspectOperations;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

final class OperationsHandler implements HandlerInterface
{
    public function __construct(private readonly InspectOperations $operations, private readonly string $view)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok(match ($this->view) {
            'jobs' => $this->operations->jobs($request->principal, $request->queryInt('page', 1), $request->queryInt('per_page', 25),
                $request->queryString('state') ?: null, $request->queryString('type') ?: null),
            'job' => $this->operations->job($request->principal, $request->intPathParam('id')),
            default => $this->operations->overview($request->principal),
        })->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
