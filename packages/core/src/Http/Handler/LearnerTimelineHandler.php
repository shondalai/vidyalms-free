<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Reporting\GetLearnerTimeline;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;

/**
 * GET /manage/learners/{user_id}/timeline
 *
 * Reading your own timeline needs a session and nothing else; reading somebody
 * else's needs the reports capability, and the use case enforces both. The
 * scope handed down is the learner-sees-their-own-rows one, which is what
 * `VisibilityScope::forUser()` was widened for.
 */
final class LearnerTimelineHandler implements HandlerInterface
{
    public function __construct(private readonly GetLearnerTimeline $timeline)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $userId = $request->intPathParam('user_id');
        $courseId = $request->queryInt('course_id', 0);
        $cursor = $request->queryString('cursor');

        return ApiResponse::ok($this->timeline->execute(
            $request->principal,
            $userId,
            VisibilityScope::forUser($userId),
            $courseId > 0 ? $courseId : null,
            $cursor === '' ? null : $cursor,
            $request->queryInt('limit', GetLearnerTimeline::DEFAULT_LIMIT),
        ))->withHeaders([
            // The highest-volume personal-data read in the product does not sit
            // in a shared cache.
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
