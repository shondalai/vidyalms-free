<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\GetAttemptForLearner;
use Shondalai\VidyaLMS\Core\Application\Assessment\SubmitAttempt;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * POST /attempts/{id}/submit
 *
 * Grades server-side and answers with the learner view, so what the client
 * sees after submitting is exactly what GET /attempts/{id} would reveal.
 */
final class SubmitAttemptHandler implements HandlerInterface
{
    private const ROUTE = 'attempts.submit';

    public function __construct(
        private readonly SubmitAttempt $submitAttempt,
        private readonly GetAttemptForLearner $getAttemptForLearner,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $attemptId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $attemptId): ApiResponse {
            $this->submitAttempt->execute($request->principal, $attemptId);

            return ApiResponse::ok(
                $this->getAttemptForLearner->execute($request->principal, $attemptId),
            );
        }, onReplay: fn (): ApiResponse => ApiResponse::ok(
            $this->getAttemptForLearner->execute($request->principal, $attemptId),
        ));
    }
}
