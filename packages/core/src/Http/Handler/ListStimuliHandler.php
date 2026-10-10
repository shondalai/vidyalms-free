<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\ListStimuli;
use Shondalai\VidyaLMS\Core\Application\Assessment\ManagedStimulus;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /manage/courses/{id}/stimuli
 */
final class ListStimuliHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListStimuli $listStimuli,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $items = $this->listStimuli->execute($request->principal, $request->intPathParam('id'));

        return ApiResponse::ok([
            'items' => array_map(
                fn (ManagedStimulus $managed): array => $this->presenters->assessment->stimulus($managed),
                $items,
            ),
        ]);
    }
}
