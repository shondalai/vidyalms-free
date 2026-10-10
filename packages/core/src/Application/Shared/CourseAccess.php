<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Shared;

use Shondalai\VidyaLMS\Core\Domain\Access\EnrolmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseRepository;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseState;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\CourseVisibility;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;

/**
 * The per-course authorization rule, asked by every course-scoped use case.
 *
 * A caller holding vidyalms.manage may act on every course. A caller holding
 * only teach, grade, enrol or reports may act only on courses where they are
 * an assigned instructor, which is a row in course_instructors. Without this,
 * any group granted vidyalms.teach could edit every course on the site.
 *
 * The checks run in a fixed order so nothing leaks: the surface capability
 * first (a guest is unauthenticated, a caller holding none of the accepted
 * capabilities is refused), then the course lookup, then instructorship. A
 * caller who could not use the surface at all therefore never learns from a
 * missing course whether that course exists.
 *
 * Every capability question carries the resource reference "course:{id}", so
 * a host that scopes its ACL per course answers per course; a host that
 * cannot scope ignores the reference and answers for the site, which is what
 * the AuthorizationPort contract already allows.
 */
final class CourseAccess
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly CourseRepository $courses,
        private readonly EnrolmentRepository $enrolments,
        private readonly ?\Shondalai\VidyaLMS\Core\Port\CourseCategories\CourseCategoryVisibility $categoryVisibility = null,
    ) {
    }

    public static function resource(int $courseId): string
    {
        return 'course:' . $courseId;
    }

    /**
     * Guests hold no capability at all, so a route addressing a child entity
     * refuses them before it looks anything up.
     */
    public function requireSignedIn(Principal $principal): void
    {
        $this->guard->requireAuthenticated($principal);
    }

    /**
     * The site-wide guard, for a row that names no course to scope to.
     *
     * It exists so a caller with no course in hand asks for a capability
     * rather than settling for "signed in", which is not a guard at all: a
     * learner holds it.
     */
    public function requireManage(Principal $principal): void
    {
        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);
    }

    /**
     * @param string ...$capabilities any one of these grants the surface;
     *                                manage additionally waives instructorship
     */
    public function require(Principal $principal, int $courseId, string ...$capabilities): Course
    {
        $course = $this->requireIncludingTrashed($principal, $courseId, ...$capabilities);

        if ($course->state === CourseState::Trashed) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Course', $courseId);
        }

        return $course;
    }

    /** Only lifecycle operations may reach a course in Trash. */
    public function requireIncludingTrashed(Principal $principal, int $courseId, string ...$capabilities): Course
    {
        $this->guard->requireAuthenticated($principal);

        $manages = $this->manages($principal, $courseId);

        if (!$manages && !$this->holdsAny($principal, $courseId, $capabilities)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw NotAuthorizedException::forCapability($capabilities[0] ?? Capability::MANAGE);
        }

        $course = $this->courses->byId($courseId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Course', $courseId);

        if (!$manages && !$this->courses->isInstructor($courseId, $principal->userId)) {
            throw new NotAuthorizedException(sprintf(
                'You are not an instructor of course %d.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $courseId,
            ));
        }

        return $course;
    }

    /**
     * The same rule as require(), answered instead of thrown. Read views use
     * it to decide whether the caller may see unpublished structure.
     *
     * @param string ...$capabilities
     */
    public function can(Principal $principal, int $courseId, string ...$capabilities): bool
    {
        if ($principal->isGuest()) {
            return false;
        }

        if ($this->manages($principal, $courseId)) {
            return true;
        }

        if (!$this->holdsAny($principal, $courseId, $capabilities)) {
            return false;
        }

        return $this->courses->isInstructor($courseId, $principal->userId);
    }

    /**
     * The "may author" test of the learner-facing routes: an unpublished
     * course, module or lesson is not found for a teacher who is not one of
     * its instructors, exactly as it is for any learner.
     */
    public function mayAuthor(Principal $principal, int $courseId): bool
    {
        return $this->courses->byId($courseId)?->state !== CourseState::Trashed
            && $this->can($principal, $courseId, Capability::TEACH);
    }

    /**
     * Public courses and intentional unlisted links are readable by anyone.
     * Private courses require a usable enrolment or course authoring access;
     * enrolment never opens an unpublished course.
     */
    public function mayRead(Principal $principal, Course $course): bool
    {
        if ($this->mayAuthor($principal, $course->id)) {
            return true;
        }

        if (!$course->isPublished() || ($course->categoryId !== null && $this->categoryVisibility !== null
            && !in_array($course->categoryId, $this->categoryVisibility->visibleCategoryIds($principal->userId), true))) {
            return false;
        }

        if ($course->isPubliclyListed() || $course->visibility === CourseVisibility::Unlisted) {
            return true;
        }

        if ($principal->isGuest()) {
            return false;
        }

        $enrolment = $this->enrolments->findByUserAndCourse($principal->userId, $course->id);

        return $enrolment !== null && ($enrolment->isActive() || $enrolment->isCompleted());
    }

    private function manages(Principal $principal, int $courseId): bool
    {
        return $this->guard->can($principal, Capability::MANAGE, self::resource($courseId));
    }

    /**
     * @param array<array-key, string> $capabilities
     */
    private function holdsAny(Principal $principal, int $courseId, array $capabilities): bool
    {
        $resource = self::resource($courseId);

        foreach ($capabilities as $capability) {
            if ($this->guard->can($principal, $capability, $resource)) {
                return true;
            }
        }

        return false;
    }
}
