<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\RegradeAttempt;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/attempts/{id}/regrade
 */
final class RegradeAttemptHandler implements HandlerInterface
{
    private const ROUTE = 'manage.attempts.regrade';

    public function __construct(
        private readonly RegradeAttempt $regradeAttempt,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $attemptId = $request->intPathParam('id');
        $regrade = RegradeRequestBody::fromBody($request->body);

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $attemptId, $regrade): ApiResponse {
            return ApiResponse::ok($this->presenters->assessment->regradeOutcome(
                $this->regradeAttempt->execute($request->principal, $attemptId, $regrade),
            ));
        });
    }
}
