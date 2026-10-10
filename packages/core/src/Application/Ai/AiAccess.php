<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;

/**
 * The authorisation rule of the AI surface, asked once rather than restated in
 * every use case.
 *
 * Three answers, and each is a different question.
 *
 * An authoring act on a course is teach on that course, which the existing
 * per-course rule already answers: a caller holding manage acts on every
 * course, a caller holding only teach acts on courses where they are an
 * assigned instructor. Nothing here weakens it.
 *
 * An act with no course in hand is manage, never "signed in". A learner holds
 * signed in, and a draft a learner could read would break the rule that no
 * draft is reachable by a learner read path.
 *
 * The administration surface, the stop and the usage report are manage. The
 * design asks for the usage report's own capability, following Moodle's
 * separate viewaiusagereport; the report provider declares its required
 * capability rather than this class hardcoding one, so narrowing it later is a
 * provider edit rather than a route edit.
 */
final class AiAccess
{
    public function __construct(
        private readonly AccessGuard $guard,
        private readonly CourseAccess $courses,
    ) {
    }

    /**
     * The AI surface itself: teach on the course when there is one, manage
     * otherwise.
     */
    public function requireAuthoring(Principal $principal, ?int $courseId): void
    {
        if ($courseId !== null && $courseId > 0) {
            $this->courses->require($principal, $courseId, Capability::TEACH);

            return;
        }

        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);
    }

    /**
     * The same question, answered rather than thrown, for a read that filters
     * rather than refuses.
     */
    public function mayAuthor(Principal $principal, ?int $courseId): bool
    {
        if ($principal->isGuest()) {
            return false;
        }

        if ($courseId !== null && $courseId > 0) {
            return $this->courses->can($principal, $courseId, Capability::TEACH);
        }

        return $this->manages($principal);
    }

    /**
     * The administration surface: routes, policies, the stop, the usage
     * report and the run log.
     */
    public function requireManage(Principal $principal): void
    {
        $this->guard->requireAny($principal, Capability::MANAGE, Capability::ADMIN);
    }

    public function manages(Principal $principal): bool
    {
        return $this->guard->can($principal, Capability::MANAGE)
            || $this->guard->can($principal, Capability::ADMIN);
    }

    /**
     * The learner's own rows, and only their own. It is a capability question
     * with no resource, so it answers "is there a person here at all".
     */
    public function requireSelf(Principal $principal): int
    {
        $this->guard->requireAuthenticated($principal);

        if ($principal->userId <= 0) {
            throw new NotAuthorizedException('This route answers for one signed-in person.');
        }

        return $principal->userId;
    }

    /**
     * A row the caller either owns or manages. It is the rule the run read and
     * the job read share: a person reads what they started, and manage reads
     * everything.
     */
    public function requireOwnerOrManage(Principal $principal, ?int $ownerUserId): void
    {
        $this->guard->requireAuthenticated($principal);

        if ($this->manages($principal)) {
            return;
        }

        if ($ownerUserId !== null && $ownerUserId === $principal->userId) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw NotAuthorizedException::forCapability(Capability::MANAGE);
    }
}
