<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\GetQuizSettingsSchema;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /manage/quizzes/{id}/settings-schema
 *
 * The whole point of the route is that the handler knows nothing: what the
 * form can offer is whatever the registries hold, and a client rendering
 * this document cannot show a control the site cannot honour.
 */
final class GetQuizSettingsSchemaHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetQuizSettingsSchema $settingsSchema,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok(
            $this->settingsSchema->execute($request->principal, $request->intPathParam('id')),
        );
    }
}
