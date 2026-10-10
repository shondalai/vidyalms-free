<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\ListProviderTypes;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;
use Shondalai\VidyaLMS\Core\Registry\Scoring\ScoringPolicyRegistry;

/**
 * GET /question-types
 */
final class ListQuestionTypesHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListProviderTypes $types,
        private readonly PresenterRegistry $presenters,
        private readonly ?ScoringPolicyRegistry $policies = null,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok([
            'items' => array_map(
                fn ($type): array => $this->presenters->assessment->questionType($type, $this->policies),
                $this->types->questionTypes($request->principal),
            ),
            'presets' => array_map(
                $this->presenters->assessment->questionPreset(...),
                $this->types->questionPresets($request->principal),
            ),
        ]);
    }
}
