<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\UpdateStimulus;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * PUT /manage/stimuli/{id}
 */
final class UpdateStimulusHandler implements HandlerInterface
{
    private const ROUTE = 'manage.stimuli.update';

    public function __construct(
        private readonly UpdateStimulus $updateStimulus,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $stimulusId = $request->intPathParam('id');
        $fields = StimulusFields::fromBody($request->body);

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $stimulusId, $fields): ApiResponse {
            return ApiResponse::ok($this->presenters->assessment->stimulus($this->updateStimulus->execute(
                $request->principal,
                $stimulusId,
                $fields->title,
                $fields->body,
                $fields->media,
                $fields->altText,
                $fields->longDescription,
            )));
        });
    }
}
