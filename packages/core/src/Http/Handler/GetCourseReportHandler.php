<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\GetCourseReport;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /manage/courses/{id}/reports/{key}
 *
 * One route for every registered report rather than one route per report:
 * adding a report is a registration, so reading one must not be a core edit
 * plus a contract operation. The query string is passed through whole, so a
 * report declares its own parameters without this handler learning them.
 *
 * A key nothing registers answers 200 with computed false and an empty list,
 * which is the registry miss contract applied to a report.
 */
final class GetCourseReportHandler implements HandlerInterface
{
    /** Query keys the handler reads itself; everything else passes through. */
    private const PAGING = ['page', 'per_page'];

    public function __construct(private readonly GetCourseReport $reports)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $parameters = [];

        foreach ($request->query as $name => $value) {
            $parameters[$name] = \is_string($value) && is_numeric($value) ? (int) $value : $value;
        }

        foreach (self::PAGING as $name) {
            $parameters[$name] = $request->queryInt($name, $name === 'page' ? 1 : 25);
        }

        return ApiResponse::ok($this->reports->execute(
            $request->principal,
            $request->intPathParam('id'),
            $request->pathParam('key'),
            $parameters,
        ));
    }
}
