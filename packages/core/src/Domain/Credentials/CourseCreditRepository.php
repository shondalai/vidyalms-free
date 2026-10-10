<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * Where an author records that this course carries six contact hours or two
 * CPE credits.
 *
 * It exists because there was no authored source at all: without it the free
 * contact hours scheme, the free credit statement element and the free credit
 * tokens all read an empty table, which is the empty-seam failure the
 * free-provider rule exists to prevent.
 */
interface CourseCreditRepository
{
    /**
     * @return list<CourseCredit>
     */
    public function forCourse(int $courseId): array;

    public function findFor(int $courseId, string $scheme): ?CourseCredit;

    /**
     * Unique on (course_id, scheme), so a second save under the same scheme
     * corrects the first rather than adding a duplicate a compliance export
     * would count twice.
     */
    public function save(CourseCredit $credit): void;

    public function delete(int $courseId, string $scheme): void;

    /**
     * Which schemes any course carries credit under, which is what the
     * element palette reads to decide whether the credit statement is worth
     * offering.
     *
     * @return list<string>
     */
    public function schemesInUse(): array;
}
