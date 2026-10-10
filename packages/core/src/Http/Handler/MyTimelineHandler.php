<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Reporting\GetLearnerTimeline;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;

/**
 * GET /me/timeline
 *
 * The learner-facing half of the timeline, and the reason it is a second route
 * rather than a query parameter.
 *
 * `GET /manage/learners/{user_id}/timeline` served both readers: a learner
 * asking for their own rows had to know their own numeric id, put it in a
 * `/manage/` path and be told by the use case that reading their own timeline
 * needs no capability. That is a manage-surface address for a personal read,
 * and every `/me/` route in this contract exists because the alternative asks
 * an app to construct an admin URL for its own user.
 *
 * The subject is the principal and can be nothing else. There is no `user_id`
 * to bind and therefore no way to ask this route for somebody else's rows,
 * which is the property that lets `/me/` sit outside the manage surface
 * entirely. The use case still runs its own check, so the guarantee is stated
 * twice rather than trusted once.
 *
 * The response is the same `LearnerTimeline` schema the manage route returns.
 * A learner-only shape would mean two readers of one table drifting apart.
 */
final class MyTimelineHandler implements HandlerInterface
{
    public function __construct(private readonly GetLearnerTimeline $timeline)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $userId = $request->principal->userId;
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
