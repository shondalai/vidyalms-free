<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Grades\RegradeGradeCell;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * POST /manage/gradebook/grades/{id}/regrade
 *
 * The idempotency key is required rather than accepted, because a replayed
 * regrade is a second row on an audit trail somebody will read.
 */
final class RegradeGradeCellHandler implements HandlerInterface
{
    private const ROUTE = 'gradebook.regrade';

    public function __construct(
        private readonly RegradeGradeCell $regrade,
        private readonly IdempotentExecution $idempotent,
        /**
         * The connection the claim commits on.
         *
         * runAtomic and not run, because the contract declares the key
         * REQUIRED here: the optional path answers a missing key by simply
         * doing the work, and a replayed regrade is a second row on an audit
         * trail somebody will read during a dispute.
         */
        private readonly DatabasePort $database,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return $this->idempotent->runAtomic($request, self::ROUTE, $this->database, function () use ($request): ApiResponse {
            return ApiResponse::ok($this->regrade->execute(
                $request->principal,
                $request->intPathParam('id'),
                $request->body,
                // The request reference on the log row is the key the caller
                // sent, which is what makes a disputed row traceable back to
                // the request that wrote it.
                $request->idempotencyKey,
            ));
        }, ['body' => $request->body]);
    }
}
