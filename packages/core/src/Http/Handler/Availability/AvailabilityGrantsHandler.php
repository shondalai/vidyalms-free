<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Availability;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\ManageAvailabilityGrants;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET and POST /manage/courses/{id}/availability/grants
 */
final class AvailabilityGrantsHandler implements HandlerInterface
{
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly ManageAvailabilityGrants $grants,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');

        if ($request->method === 'GET') {
            $userId = $request->queryInt('user_id', 0);

            return ApiResponse::ok($this->grants->list(
                $request->principal,
                $courseId,
                $userId > 0 ? $userId : null,
                max(1, $request->queryInt('page', 1)),
                min(self::MAX_PER_PAGE, max(1, $request->queryInt('per_page', self::DEFAULT_PER_PAGE))),
            ));
        }

        return $this->idempotent->run(
            $request,
            'availability.grant.create',
            fn (): ApiResponse => ApiResponse::ok(
                $this->grants->create($request->principal, $courseId, $request->body),
            ),
        );
    }
}
