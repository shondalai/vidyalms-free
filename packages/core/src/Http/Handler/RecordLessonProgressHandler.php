<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Progress\RecordLessonProgress;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * POST /lessons/{id}/progress
 *
 * The player heartbeat. The body names the caller's enrolment; the use case
 * verifies ownership, so a foreign enrolment id cannot record anything.
 */
final class RecordLessonProgressHandler implements HandlerInterface
{
    private const ROUTE = 'lessons.progress';

    public function __construct(
        private readonly RecordLessonProgress $recordLessonProgress,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $lessonId = $request->intPathParam('id');
        $body = $request->body;

        $enrolmentId = $body['enrolment_id'] ?? null;

        if (!\is_int($enrolmentId) || $enrolmentId < 1) {
            throw ApiProblem::validation(['enrolment_id' => 'A positive enrolment id is required.']);
        }

        $progressPercent = $this->optionalNumber($body, 'progress_percent');
        $secondsDelta = $this->optionalInt($body, 'seconds_delta');
        $positionSeconds = $this->optionalInt($body, 'position_seconds');
        $completed = ($body['completed'] ?? false) === true;

        $operation = function () use (
            $request,
            $enrolmentId,
            $lessonId,
            $progressPercent,
            $secondsDelta,
            $positionSeconds,
            $completed,
        ): ApiResponse {
            $this->recordLessonProgress->execute(
                $request->principal,
                $enrolmentId,
                $lessonId,
                $progressPercent,
                $secondsDelta,
                $positionSeconds,
                $completed,
            );

            return ApiResponse::noContent();
        };

        return $this->idempotent->run($request, self::ROUTE, $operation);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalNumber(array $body, string $field): ?float
    {
        $value = $body[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!\is_int($value) && !\is_float($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$field => 'Must be a number.']);
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalInt(array $body, string $field): ?int
    {
        $value = $body[$field] ?? null;

        if ($value === null) {
            return null;
        }

        if (!\is_int($value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$field => 'Must be an integer.']);
        }

        return $value;
    }
}
