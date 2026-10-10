<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\AddQuestion;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/quizzes/{id}/questions
 */
final class AddQuestionHandler implements HandlerInterface
{
    private const ROUTE = 'manage.quizzes.questions.add';

    public function __construct(
        private readonly AddQuestion $addQuestion,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $quizId = $request->intPathParam('id');
        $body = $request->body;

        $type = Body::requiredString($body, 'type');
        $definition = Body::object($body, 'definition', true);
        $points = Body::number($body, 'points', 1.0);
        // A column rather than a definition field, and the use case pairs it
        // with the policy: a definition resolving to "none" is refused unless
        // it is authored unscored. Without a way to send it, the picker's
        // opinion scale answered a 422 naming a field nobody could set.
        $scored = Body::bool($body, 'scored', true);

        return $this->idempotent->run(
            $request,
            self::ROUTE,
            function () use ($request, $quizId, $type, $definition, $points, $scored): ApiResponse {
                return ApiResponse::created($this->presenters->assessment->question(
                    $this->addQuestion->execute(
                        $request->principal,
                        $quizId,
                        $type,
                        $definition,
                        $points,
                        $scored,
                    ),
                ));
            },
        );
    }
}
