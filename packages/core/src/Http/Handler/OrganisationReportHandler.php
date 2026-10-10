<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Reporting\GetOrganisationReport;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /manage/organisations/{id}/reports/{key}
 *
 * The organisation is a path segment and never a parameter, which is the rule
 * 04-api-contract.md sets for every organisation-scoped read: a tenant that can
 * arrive in the query string is a tenant a caller can change.
 */
final class OrganisationReportHandler implements HandlerInterface
{
    public function __construct(private readonly GetOrganisationReport $reports)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->reports->read(
            $request->principal,
            $request->intPathParam('id'),
            $request->pathParam('key'),
            SiteReportHandler::parametersFrom($request),
        ))->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
