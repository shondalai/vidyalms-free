<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Platform\SearchUsers;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /admin/users
 */
final class SearchUsersHandler implements HandlerInterface
{
    public function __construct(
        private readonly SearchUsers $searchUsers,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok([
            'items' => array_map(
                $this->presenters->shared->userAccount(...),
                $this->searchUsers->execute(
                    $request->principal,
                    $request->queryString('q'),
                    $request->queryInt('limit', 10),
                ),
            ),
        ]);
    }
}
