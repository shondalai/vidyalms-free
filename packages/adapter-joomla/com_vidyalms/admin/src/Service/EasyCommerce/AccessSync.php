<?php

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service\EasyCommerce;

defined('_JEXEC') or die;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/** The single adapter-to-Core seam, also usable without Joomla's application bootstrap. */
final class AccessSync
{
    /**
     * @param \Closure(int): ?Principal $actor
     * @param \Closure(Principal, string, string, string, int, array, bool, ?string, string): void $sync
     */
    public function __construct(private readonly \Closure $actor, private readonly \Closure $sync)
    {
    }

    /** @param \Closure(int, array): void $remember */
    public function execute(string $type, int $id, AccessState $state, int $previousUser, array $previousCourses, \Closure $remember): void
    {
        $courses = CourseMapping::ids([...$previousCourses, ...$state->courses]);
        if ($courses === []) {
            return;
        }
        if ($state->needsReview) {
            // Preserve existing access and do not create a new grant from an
            // ambiguous refund. The queue retains a visible, retryable warning.
            throw new \RuntimeException('PLG_SYSTEM_VIDYALMS_EASYCOMMERCE_REFUND_REVIEW');
        }
        $actor = null;
        foreach ($courses as $course) {
            $candidate = ($this->actor)($course);
            if ($candidate === null || ($actor !== null && $actor->userId !== $candidate->userId)) {
                throw new \RuntimeException('PLG_SYSTEM_VIDYALMS_EASYCOMMERCE_ACTOR_REQUIRED');
            }
            $actor = $candidate;
        }
        $remember($state->userId, $courses);
        // Core atomically revokes old-account/stale-course grants. A deleted or
        // guest shop object must still reach Core with active=false and userId=0.
        ($this->sync)($actor, 'easycommerce', $type, (string) $id, $state->userId,
            $state->courses, $state->userId > 0 && $state->active, $state->expiresAt, $state->reason);
        // Keep the last non-empty course set even after revocation, to authorize a
        // later mapping removal, deletion, retry or reactivation with a real actor.
        $remember($state->userId, $state->courses === [] ? $courses : $state->courses);
    }
}
