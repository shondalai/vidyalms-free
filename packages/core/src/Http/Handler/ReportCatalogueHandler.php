<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationAccess;
use Shondalai\VidyaLMS\Core\Application\Reporting\GetOrganisationReport;
use Shondalai\VidyaLMS\Core\Application\Reporting\ListReportDefinitions;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /manage/reports and GET /manage/organisations/{id}/reports
 *
 * The catalogue a manage screen builds its report picker and its filter forms
 * from. The site path is registered before `/manage/reports/{key}` for
 * tidiness only: the paths differ in segment count, so neither can shadow the
 * other.
 *
 * **The organisation half is a route rather than a flag nobody set.** The use
 * case has always taken the flag and filtered organisation-scoped providers
 * out of the site list, and the handler was constructed exactly once with the
 * default, so every organisation report was absent from the site catalogue and
 * present only in a call nothing made. A picker cannot offer what no route
 * lists, so a screen had to name the organisation reports by hand, which is
 * the edit the registry exists to remove.
 */
final class ReportCatalogueHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListReportDefinitions $reports,
        private readonly bool $organisationScoped = false,
        /**
         * The tenant check, for the organisation path only. It is required
         * there and refused without: a catalogue of what a tenant may run is
         * a read about that tenant, so it resolves the same scope its own
         * report route does before it lists anything.
         */
        private readonly ?OrganisationAccess $access = null,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        if ($this->organisationScoped && $this->access !== null) {
            $this->access->require(
                $request->principal,
                $request->intPathParam('id'),
                GetOrganisationReport::ACTION,
            );
        }

        return ApiResponse::ok($this->reports->execute($request->principal, $this->organisationScoped));
    }
}
