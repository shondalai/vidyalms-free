<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Progress;

interface CourseProgressRepository
{
    public function findByEnrolment(int $enrolmentId): ?CourseProgress;

    /**
     * Idempotent upsert on the enrolment id.
     */
    public function save(CourseProgress $progress): void;
}
