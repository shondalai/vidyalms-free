<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\RemoveStimulus;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * DELETE /manage/stimuli/{id}
 */
final class RemoveStimulusHandler implements HandlerInterface
{
    private const ROUTE = 'manage.stimuli.remove';

    public function __construct(
        private readonly RemoveStimulus $removeStimulus,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $stimulusId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $stimulusId): ApiResponse {
            $this->removeStimulus->execute($request->principal, $stimulusId);

            return ApiResponse::noContent();
        });
    }
}
