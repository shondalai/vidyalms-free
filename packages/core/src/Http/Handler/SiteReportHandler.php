<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Reporting\GetSiteReport;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /manage/reports/{key}
 *
 * The site-scoped sibling of the course-scoped report route, so revenue, tax,
 * churn, coupon usage and payouts have somewhere to hang from without a core
 * route each.
 *
 * **It is registered strictly after `/manage/reports/overview`.** Route
 * matching is first-registration-wins with no literal-over-parameter
 * precedence, so registering this first would swallow the overview alias and
 * answer the registry-miss shape for a key nothing registers.
 */
final class SiteReportHandler implements HandlerInterface
{
    /** Query keys the handler reads itself; everything else passes through. */
    private const PAGING = ['page', 'per_page'];

    public function __construct(private readonly GetSiteReport $reports)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->reports->execute(
            $request->principal,
            $request->pathParam('key'),
            self::parametersFrom($request),
        ))->withHeaders(['Cache-Control' => 'private, no-store']);
    }

    /**
     * The query string, whole, with the two paging keys coerced.
     *
     * A report declares its own parameters, so the route learns none of them:
     * anything else the caller sent is passed through untouched and the
     * provider's own schema is what validates it.
     *
     * @return array<string, mixed>
     */
    public static function parametersFrom(ApiRequest $request): array
    {
        $parameters = [];

        foreach ($request->query as $name => $value) {
            $parameters[$name] = \is_string($value) && is_numeric($value) ? (int) $value : $value;
        }

        foreach (self::PAGING as $name) {
            $parameters[$name] = $request->queryInt($name, $name === 'page' ? 1 : 25);
        }

        return $parameters;
    }
}
