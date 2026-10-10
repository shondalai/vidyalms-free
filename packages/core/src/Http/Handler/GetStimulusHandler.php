<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\GetStimulus;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /manage/stimuli/{id}
 */
final class GetStimulusHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetStimulus $getStimulus,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->presenters->assessment->stimulus(
            $this->getStimulus->execute($request->principal, $request->intPathParam('id')),
        ));
    }
}
