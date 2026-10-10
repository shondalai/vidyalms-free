<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\AssignInstructor;
use Shondalai\VidyaLMS\Core\Application\Curriculum\Instructor;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/courses/{id}/instructors
 */
final class AssignInstructorHandler implements HandlerInterface
{
    private const ROUTE = 'manage.courses.instructors.assign';

    public function __construct(
        private readonly AssignInstructor $assignInstructor,
        private readonly UsersPort $users,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $courseId = $request->intPathParam('id');
        $userId = Body::requiredInt($request->body, 'user_id');
        $role = Body::nullableString($request->body, 'role', 'lead') ?? 'lead';

        if ($userId < 1) {
            throw ApiProblem::validation(['user_id' => 'A positive user id is required.']);
        }

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $courseId, $userId, $role): ApiResponse {
            if (!$this->users->exists($userId)) {
                throw ApiProblem::notFound('No such user.');
            }

            $this->assignInstructor->execute($request->principal, $courseId, $userId, $role);

            return ApiResponse::created($this->presenters->curriculum->instructor(
                new Instructor($userId, $this->users->findById($userId)?->name, $role),
            ));
        });
    }
}
