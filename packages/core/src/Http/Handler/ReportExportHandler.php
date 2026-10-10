<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Reporting\GetOrganisationReport;
use Shondalai\VidyaLMS\Core\Application\Reporting\GetReportRun;
use Shondalai\VidyaLMS\Core\Application\Reporting\StartReportExport;
use Shondalai\VidyaLMS\Core\Domain\Reporting\ReportRun;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * POST /manage/reports/{key}/exports
 * POST /manage/organisations/{id}/reports/{key}/exports
 *
 * 202 Accepted with the run id and a Retry-After hint, which is the shape
 * Canvas uses for content exports and the shape 11-risks.md's "computed by
 * scheduled jobs, never on page load" policy needs to be enforceable at all.
 *
 * The two paths share this handler because they differ in exactly one thing,
 * which is where the scope comes from, and the response shape a caller polls
 * afterwards is identical.
 *
 * **It is a mutation and it takes an Idempotency-Key like every other one.**
 * It did not, and a retry was expensive in exactly the way this route makes
 * expensive: three identical POSTs produced three runs, three artefacts of
 * personal data on disk and three delivery emails, all under one scope digest.
 * A 202 is precisely the answer a client retries, because the work is not
 * finished when the response arrives.
 */
final class ReportExportHandler implements HandlerInterface
{
    private const ROUTE = 'manage.reports.exports';
    private const ROUTE_ORGANISATION = 'manage.organisations.reports.exports';

    public function __construct(
        private readonly StartReportExport $site,
        private readonly GetOrganisationReport $organisation,
        private readonly IdempotentExecution $idempotent,
        private readonly bool $organisationScoped = false,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $route = $this->organisationScoped ? self::ROUTE_ORGANISATION : self::ROUTE;

        return $this->idempotent->run($request, $route, function () use ($request): ApiResponse {
            $key = $request->pathParam('key');
            $format = $request->queryString('format', 'csv');
            $delivery = $request->queryString('delivery');
            $parameters = SiteReportHandler::parametersFrom($request);

            $run = $this->organisationScoped
                ? $this->organisation->export(
                    $request->principal,
                    $request->intPathParam('id'),
                    $key,
                    $format,
                    $delivery === '' ? null : $delivery,
                    $parameters,
                )
                : $this->site->execute(
                    $request->principal,
                    $key,
                    $format,
                    $delivery === '' ? null : $delivery,
                    $parameters,
                );

            return new ApiResponse(202, self::body($run), [
                // A caller that polls immediately learns nothing and costs a
                // round trip, so the hint is on the response rather than in
                // prose.
                'Retry-After' => (string) max(1, GetReportRun::retryAfter($run)),
                'Cache-Control' => 'private, no-store',
            ]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private static function body(ReportRun $run): array
    {
        return [
            'id' => $run->id,
            'report' => $run->reportKey,
            'format' => $run->formatKey,
            'delivery' => $run->deliveryKey,
            'state' => $run->state,
            'row_count' => $run->rowCount,
            'organisation_id' => $run->organisationId,
            'expires_at' => $run->expiresAt->format(DATE_ATOM),
        ];
    }
}
