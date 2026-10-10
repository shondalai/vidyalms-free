<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Assignments\SubmitAssignment;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * POST /assignments/{id}/submissions
 *
 * Assignments are one to one with their lesson, so the free API addresses
 * them by the assignment lesson id the learner already has from the
 * curriculum. File entries reference private storage paths registered after
 * a signed upload; no bytes travel through this endpoint.
 */
final class SubmitAssignmentHandler implements HandlerInterface
{
    private const ROUTE = 'assignments.submissions.create';

    public function __construct(
        private readonly SubmitAssignment $submitAssignment,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $lessonId = $request->intPathParam('id');
        $body = $request->body;

        $textResponse = $body['text_response'] ?? null;

        if ($textResponse !== null && !\is_string($textResponse)) {
            throw ApiProblem::validation(['text_response' => 'Must be a string.']);
        }

        $files = $this->filesFrom($body);

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $lessonId, $textResponse, $files): ApiResponse {
            $submission = $this->submitAssignment->execute($request->principal, $lessonId, $textResponse, $files);

            return ApiResponse::created(Presenter::submission($submission));
        }, onReplay: function (array $stored) use ($request, $lessonId): ApiResponse {
            $submission = $this->submitAssignment->currentForLearner($request->principal, $lessonId, (int) ($stored['id'] ?? 0));
            return ApiResponse::created(Presenter::submission($submission));
        });
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return list<array<string, mixed>>
     */
    private function filesFrom(array $body): array
    {
        $raw = $body['files'] ?? [];

        if (!\is_array($raw)) {
            throw ApiProblem::validation(['files' => 'Must be an array of file entries.']);
        }

        $files = [];

        foreach (array_values($raw) as $index => $entry) {
            if (!\is_array($entry)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ApiProblem::validation([sprintf('files.%d', $index) => 'Each file entry must be an object.']);
            }

            /** @var array<string, mixed> $entry */
            $files[] = $entry;
        }

        return $files;
    }
}
