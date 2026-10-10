<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * The rule for a route that addresses both an organisation and a course.
 *
 * What the course half tests is deliberately **not** instructorship.
 * CourseAccess::require() refuses anyone who is not a course_instructors row
 * unless they hold site management on the course, and an organisation
 * administrator is deliberately neither, so an intersection with CourseAccess
 * would be empty for every organisation role on every route and the seam would
 * be one nothing can use. What the course half actually tests is whether the
 * course is inside the organisation's reachable set, which is the site owner's
 * release record.
 *
 * So this is OrganisationAccess::require() plus CourseReleasePolicy::require(),
 * written as a third small object rather than threaded as an optional
 * organisation id through a shipped signature every course-scoped use case
 * already calls. It was inlined at three call sites before it had a name.
 *
 * **The organisation rule may only ever narrow.** An organisation cannot grant
 * a course capability the course rule refuses, and it cannot reach a course
 * the site owner has not released. Both halves must pass; neither can stand in
 * for the other.
 */
final class ComposedAccess
{
    public function __construct(
        private readonly OrganisationAccess $organisations,
        private readonly CourseReleasePolicy $releases,
    ) {
    }

    /**
     * Both halves, in the fixed order: the organisation rule first, so a
     * caller with no claim on the organisation never learns from the release
     * record which courses it holds.
     */
    public function require(
        Principal $actor,
        int $organisationId,
        int $courseId,
        string $action = 'learning',
    ): ComposedGrant {
        $scope = $this->organisations->require($actor, $organisationId, $action);

        return ComposedGrant::of($scope, $this->releases->require($scope, $courseId));
    }

    /**
     * The course half alone, for a caller already holding a scope the
     * organisation half produced.
     *
     * It exists so a use case that resolved its scope several statements ago
     * still asks the release question through this object rather than reaching
     * past it, which is what keeps the pairing one thing.
     */
    public function requireRelease(OrgScope $scope, int $courseId): ComposedGrant
    {
        return ComposedGrant::of($scope, $this->releases->require($scope, $courseId));
    }

    /** The same question answered instead of thrown, for a listing path. */
    public function allows(OrgScope $scope, int $courseId): bool
    {
        return $this->releases->allows($scope, $courseId);
    }
}
