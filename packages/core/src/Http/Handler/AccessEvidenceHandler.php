<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Access\CollectAccessEvidence;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET /manage/learners/{user_id}/courses/{course_id}/access-evidence
 *
 * The free dispute-evidence bundle. It is free because a chargeback on a
 * CMS-shop order arrives whether or not a gateway package is installed, and
 * because the facts it carries (what was granted, what was consumed, when) can
 * be reconstructed later from nothing else.
 */
final class AccessEvidenceHandler implements HandlerInterface
{
    public function __construct(
        private readonly CollectAccessEvidence $evidence,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $userId = $request->intPathParam('user_id');
        $courseId = $request->intPathParam('course_id');
        $facts = $this->evidence->execute($request->principal, $userId, $courseId);
        return ApiResponse::ok($facts)->withHeaders(['Cache-Control' => 'private, no-store']);
    }

}
