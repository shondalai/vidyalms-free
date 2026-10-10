<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Availability;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\PreviewAvailabilityRule;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\Body;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * POST /manage/availability/preview
 */
final class PreviewAvailabilityHandler implements HandlerInterface
{
    public function __construct(
        private readonly PreviewAvailabilityRule $preview,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return $this->idempotent->run($request, 'availability.preview', function () use ($request): ApiResponse {
            $userId = Body::requiredInt($request->body, 'user_id');
            $subjectType = Body::nullableString($request->body, 'subject_type');
            $subjectId = $request->body['subject_id'] ?? null;
            $courseId = $request->body['course_id'] ?? null;
            $tree = $request->body['tree'] ?? null;

            $subject = null;

            if ($subjectType !== null && \is_numeric($subjectId)) {
                if (!GateSubject::isKnownType($subjectType)) {
                    throw ValidationException::forField('subject_type', 'Unknown subject type.');
                }

                $subject = new GateSubject($subjectType, (int) $subjectId);
            }

            return ApiResponse::ok($this->preview->execute(
                $request->principal,
                $userId,
                $subject,
                \is_numeric($courseId) ? (int) $courseId : null,
                \is_array($tree) ? $tree : null,
                $this->instant(Body::nullableString($request->body, 'as_of')),
            ));
        });
    }

    private function instant(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw ValidationException::forField('as_of', 'Not a valid instant.');
        }
    }
}
