<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\BuildXapiStatements;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /manage/attempts/{id}/xapi[?publish=1]
 *
 * The statements the free build must emit for one attempt, answered as
 * documents rather than posted anywhere. The route exists so a site owner,
 * a conformance run and a paid forwarder all read the same bytes, and so
 * the emitter is provable without an LRS.
 *
 * Reading is the default and publishing is asked for. Publishing on every
 * call made a read route a write: it fanned every statement out to every host
 * listener again, so a forty-question attempt fired forty-three host events
 * per GET, and a listener that awards points or posts a webhook does not see
 * the idempotency key unless it chooses to.
 */
final class GetAttemptXapiHandler implements HandlerInterface
{
    /** The query parameter that turns the read into an emission. */
    public const PUBLISH = 'publish';

    public function __construct(
        private readonly BuildXapiStatements $buildXapiStatements,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->buildXapiStatements->execute(
            $request->principal,
            $request->intPathParam('id'),
            $request->queryBool(self::PUBLISH),
        ));
    }
}
