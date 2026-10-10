<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\ListImporters;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /import/importers
 *
 * It has to be registered before GET /import/{id}: route matching is first
 * registration wins with no precedence of a literal over a parameter, so the
 * other order would serve this path to the job handler as job id "importers".
 */
final class ListImportersHandler implements HandlerInterface
{
    public function __construct(private readonly ListImporters $importers)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok(['items' => $this->importers->execute($request->principal)]);
    }
}
