<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * Read-only view into the Curriculum context. Credentials shows course titles
 * on certificates and verification pages but never writes Curriculum tables.
 */
interface CourseTitles
{
    public function titleOf(int $courseId): ?string;

    /**
     * The course's own description, which Open Badges 3.0 requires on an
     * Achievement. A course with none answers null and the credential falls
     * back to the achievement name rather than emitting an empty string,
     * which is schema-valid and semantically empty.
     */
    public function summaryOf(int $courseId): ?string;
}
