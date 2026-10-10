<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Curriculum\CreateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\GetManagedCourse;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CompletionRule;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseVisibility;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/courses
 */
final class CreateCourseHandler implements HandlerInterface
{
    private const ROUTE = 'manage.courses.create';

    public function __construct(
        private readonly CreateCourse $createCourse,
        private readonly GetManagedCourse $getManagedCourse,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $this->createCourse->authorise($request->principal);
        $body = $request->body;
        $title = Body::requiredString($body, 'title');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $body, $title): ApiResponse {
            $course = $this->createCourse->execute(
                $request->principal,
                $title,
                Body::nullableString($body, 'slug'),
                Body::nullableString($body, 'summary'),
                Body::nullableString($body, 'description'),
                Body::nullableString($body, 'image'),
                Body::nullableInt($body, 'category_id'),
                Body::nullableString($body, 'level'),
                Body::nullableString($body, 'language'),
                $this->visibility($body),
                Body::nullableString($body, 'price_mode', 'free') ?? 'free',
                $this->completionRule($body),
                Body::nullableInt($body, 'completion_threshold'),
                Body::bool($body, 'featured', false),
            );

            return ApiResponse::created(
                $this->presenters->curriculum->managedCourseDetail($this->getManagedCourse->reload($course->id)),
            );
        });
    }

    /**
     * @param array<string, mixed> $body
     */
    private function visibility(array $body): CourseVisibility
    {
        $value = Body::nullableString($body, 'visibility', CourseVisibility::Public->value) ?? CourseVisibility::Public->value;

        return CourseVisibility::tryFrom($value)
            ?? throw ApiProblem::validation(['visibility' => 'Must be public, private or unlisted.']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function completionRule(array $body): CompletionRule
    {
        $value = Body::nullableString($body, 'completion_rule', CompletionRule::AllLessons->value)
            ?? CompletionRule::AllLessons->value;

        return CompletionRule::tryFrom($value)
            ?? throw ApiProblem::validation(['completion_rule' => 'Must be all_lessons, percentage or pass_final.']);
    }
}
