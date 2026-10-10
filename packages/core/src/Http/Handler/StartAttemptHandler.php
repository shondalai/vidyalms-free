<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\StartAttempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptDeadline;
use Shondalai\VidyaLMS\Core\Domain\Assessment\QuizRepository;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * POST /quizzes/{id}/attempts
 *
 * The payload carries expires_at, the server deadline of a timed quiz
 * derived by AttemptDeadline; the quiz row is read here purely to present
 * that deadline, the rule itself lives in the domain.
 */
final class StartAttemptHandler implements HandlerInterface
{
    private const ROUTE = 'quizzes.attempts.start';

    public function __construct(
        private readonly StartAttempt $startAttempt,
        private readonly QuizRepository $quizzes,
        private readonly IdempotentExecution $idempotent,
        private readonly \Shondalai\VidyaLMS\Core\Application\Assessment\GetAttemptForLearner $current,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $quizId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $quizId): ApiResponse {
            $attempt = $this->startAttempt->execute($request->principal, $quizId);
            $quiz = $this->quizzes->byId($attempt->quizId);
            $expiresAt = AttemptDeadline::of($attempt, $quiz?->timeLimitSeconds);

            return ApiResponse::created(Presenter::attemptStarted($attempt, $expiresAt));
        }, onReplay: function (array $stored) use ($request): ApiResponse {
            $this->current->execute($request->principal, (int) ($stored['id'] ?? 0));
            return ApiResponse::created($stored);
        });
    }
}
