<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

/**
 * A condition satisfied by a learner action rather than by a read: a password
 * gate, an acknowledgement, a review status. Default when absent: no action
 * shape.
 *
 * Unused by the free build and present from the first release, because such a
 * condition cannot be hosted by an interface that assumes every condition is
 * a pure read, and adding the field afterwards is a breaking change to a
 * published value object.
 */
interface RequiresLearnerAction
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed> the action shape a client renders
     */
    public function unlockAction(array $payload): array;
}
