<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Credentials;

/**
 * The three course facts a certificate freezes that no other collaborator
 * supplies.
 *
 * They are read once, at issue, and frozen onto the row like everything else,
 * so a category renamed or an instructor reassigned in 2028 cannot change what
 * a 2026 certificate says. That is why this is a narrow read-only view rather
 * than a live lookup inside the token resolver: a resolver that queried at
 * render time would disagree with the snapshot beside it on the very next
 * render, which is the failure the frozen snapshot exists to prevent.
 *
 * Each answer is nullable and a null is not an error: the published miss
 * direction for a token is that the placeholder is left intact in the output,
 * and an element with no value draws its empty block.
 */
interface IssuanceCourseFacts
{
    /** The catalogue category's own label, which is a host taxonomy on both platforms. */
    public function categoryOf(int $courseId): ?string;

    /** The course instructors as one presentable string, lead roles first. */
    public function instructorsOf(int $courseId): ?string;

    /**
     * The course total this enrolment achieved, as the progress context
     * computed it. The element never computes one: a second answer to a
     * question another context owns disagrees with it the first time a regrade
     * lands.
     */
    public function gradeFor(?int $enrolmentId): ?string;
}
