<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Commerce;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * Who a webhook, a cron pass or a fan-out acts as.
 *
 * Principal has exactly user() and guest(), and both GrantEntitlement and
 * RevokeEntitlement demand ENROL_OTHERS **on the course**, so a background
 * run had no actor at all and every bridge invented its own three-candidate
 * ladder. Promoting the ladder here is not tidiness: the bridges asked the
 * site-wide question while the use case authorises per course and
 * additionally requires a course_instructors row for a principal without
 * MANAGE, so a resolver that answers once could hand back an actor the use
 * case then refuses, and a membership fan-out over forty courses could have
 * thirty-nine grants fail while the resolver believed it had succeeded.
 *
 * **A fan-out therefore resolves once per course**, and a course with no
 * eligible actor is answered with null. The caller leaves that order paid and
 * unfulfilled with the same visible admin alert a fulfilment miss raises,
 * rather than granting part of it.
 *
 * **There is no synthetic principal here and there must never be one.** A
 * principal that bypassed AccessGuard would put a hole in the access spine to
 * save a helper class, and it would be a hole with the word "system" written
 * on it that every later feature would reach for. Whichever real account
 * answers is what lands in entitlements.granted_by, which is what makes a
 * background grant auditable at all.
 *
 * The ladder, in order:
 *
 * 1. The current actor, when there is one. A return-URL fulfilment runs
 *    inside the buyer's own request and an admin's manual grant runs inside
 *    theirs, and neither should be attributed to a configured robot.
 * 2. The account named by the `commerce.background_actor_user_id` setting.
 * 3. Nobody. There is deliberately no fallback to "the site's first
 *    administrator": the shipped bridges' ladder ends there, and picking an
 *    arbitrary human to attribute automated grants to is a decision a site
 *    owner should make rather than one a helper should guess. An unset
 *    setting is a stated configuration gap, and the alert names it.
 */
final class BackgroundActorResolver
{
    /** The one settings row that names the account. */
    public const SETTING = 'commerce.background_actor_user_id';

    public function __construct(
        private readonly CourseAccess $courseAccess,
        private readonly AccessGuard $guard,
        private readonly Settings $settings,
    ) {
    }

    /**
     * The first candidate that actually holds ENROL_OTHERS where it is needed.
     *
     * @param ?int       $courseId the course the grant is for, which is the
     *                             scope the use case will authorise at. Null
     *                             asks the site-wide question, which is right
     *                             only for a site-scoped operation.
     * @param ?Principal $current  the actor already in hand, when a background
     *                             run happens inside somebody's request
     */
    public function resolve(?int $courseId = null, ?Principal $current = null): ?Principal
    {
        foreach ($this->candidates($current) as $candidate) {
            if ($this->canEnrolOthers($candidate, $courseId)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return list<Principal>
     */
    private function candidates(?Principal $current): array
    {
        $candidates = [];

        if ($current !== null && !$current->isGuest()) {
            $candidates[] = $current;
        }

        // getIntish rather than getInt: nothing writes this row yet, so
        // whichever screen or CLI lands first decides whether it holds 1 or
        // "1", and under the strict read the string form answered nobody. The
        // failure mode was an order left paid and unfulfilled with the
        // configuration gap reported as if the setting had never been made.
        $configured = $this->settings->getIntish(self::SETTING);

        if ($configured > 0) {
            $candidates[] = Principal::user($configured);
        }

        return $candidates;
    }

    private function canEnrolOthers(Principal $candidate, ?int $courseId): bool
    {
        if ($courseId === null) {
            return $this->guard->can($candidate, Capability::ENROL_OTHERS);
        }

        return $this->courseAccess->can($candidate, $courseId, Capability::ENROL_OTHERS);
    }
}
