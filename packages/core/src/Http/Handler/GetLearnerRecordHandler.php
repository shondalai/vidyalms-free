<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\GetLearnerRecord;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /admin/learners/{user_id}
 */
final class GetLearnerRecordHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetLearnerRecord $getLearnerRecord,
        private readonly string $verificationBaseUrl,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->presenters->platform->learnerRecord(
            $this->getLearnerRecord->execute($request->principal, $request->intPathParam('user_id')),
            $this->verificationBaseUrl,
        ));
    }
}
