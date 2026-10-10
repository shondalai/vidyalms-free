<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assessment\GetItemAnalysisReport;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /manage/courses/{id}/reports/item-analysis
 *
 * A site with no registered provider answers 200 with computed false and an
 * empty list, so the screen renders its placeholder rather than an error.
 */
final class GetItemAnalysisHandler implements HandlerInterface
{
    public function __construct(
        private readonly GetItemAnalysisReport $itemAnalysis,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $parameters = [
            'page' => $request->queryInt('page', 1),
            'per_page' => $request->queryInt('per_page', 25),
            'sort' => $request->queryString('sort', 'position'),
            'order' => $request->queryString('order', 'asc'),
        ];

        $quizId = $request->queryInt('quiz_id', 0);

        if ($quizId > 0) {
            $parameters['quiz_id'] = $quizId;
        }

        $policyKey = $request->queryString('policy_key');

        if ($policyKey !== '') {
            $parameters['policy_key'] = $policyKey;
        }

        $minResponses = $request->queryInt('min_responses', 0);

        if ($minResponses > 0) {
            $parameters['min_responses'] = $minResponses;
        }

        return ApiResponse::ok(
            $this->itemAnalysis->execute($request->principal, $request->intPathParam('id'), $parameters),
        );
    }
}
