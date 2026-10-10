<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assignments\ConfigureAssignment;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * PUT /manage/lessons/{id}/assignment
 *
 * A full replacement: an omitted optional field takes its documented default,
 * not its stored value, which is what "PUT" promises.
 */
final class ConfigureAssignmentHandler implements HandlerInterface
{
    private const ROUTE = 'manage.lessons.assignment';

    public function __construct(
        private readonly ConfigureAssignment $configureAssignment,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $lessonId = $request->intPathParam('id');
        $body = $request->body;

        $instructions = Body::nullableString($body, 'instructions');
        $maxPoints = Body::number($body, 'max_points', 100.0);
        $types = Body::stringList($body, 'allowed_file_types');
        $maxFileSizeKb = Body::nullableInt($body, 'max_file_size_kb');
        $maxFiles = Body::nullableInt($body, 'max_files', 1) ?? 1;
        $dueAt = Body::nullableString($body, 'due_at');
        $allowResubmission = Body::bool($body, 'allow_resubmission', false);
        $passPercentage = isset($body['pass_percentage']) ? Body::number($body, 'pass_percentage') : null;
        $rubric = $body['rubric'] ?? [];
        if (!is_array($rubric)) {
            throw \Shondalai\VidyaLMS\Core\Http\ApiProblem::validation(['rubric' => 'Must be a list of criteria.']);
        }

        return $this->idempotent->run($request, self::ROUTE, function () use (
            $request,
            $lessonId,
            $instructions,
            $maxPoints,
            $types,
            $maxFileSizeKb,
            $maxFiles,
            $dueAt,
            $allowResubmission,
            $passPercentage,
            $rubric,
        ): ApiResponse {
            return ApiResponse::ok($this->presenters->assessment->assignment($this->configureAssignment->execute(
                $request->principal,
                $lessonId,
                $instructions,
                $maxPoints,
                $types === [] ? null : implode(',', $types),
                $maxFileSizeKb,
                $maxFiles,
                $dueAt,
                $allowResubmission,
                $passPercentage,
                $rubric,
            )));
        });
    }
}
