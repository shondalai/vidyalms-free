<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\CreateStimulus;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/courses/{id}/stimuli
 */
final class CreateStimulusHandler implements HandlerInterface
{
    private const ROUTE = 'manage.stimuli.create';

    public function __construct(
        private readonly CreateStimulus $createStimulus,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');
        $fields = StimulusFields::fromBody($request->body);

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $courseId, $fields): ApiResponse {
            return ApiResponse::created($this->presenters->assessment->stimulus($this->createStimulus->execute(
                $request->principal,
                $courseId,
                $fields->title,
                $fields->body,
                $fields->media,
                $fields->altText,
                $fields->longDescription,
            )));
        });
    }
}
