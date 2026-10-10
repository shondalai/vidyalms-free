<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

final class GradebookSubmission
{
    /**
     * @param list<GradebookFile> $files
     * @param array<string, mixed> $assessment
     */
    public function __construct(
        public readonly int $id,
        public readonly int $assignmentId,
        public readonly int $enrolmentId,
        public readonly int $attemptNumber,
        public readonly ?string $textResponse,
        public readonly \DateTimeImmutable $submittedAt,
        public readonly float $maxPoints,
        public readonly bool $allowResubmission,
        public readonly array $files,
        public readonly array $assessment = [],
    ) {
    }
}
