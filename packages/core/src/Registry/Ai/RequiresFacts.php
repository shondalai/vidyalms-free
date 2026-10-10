<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * The named learner or item facts this task is handed.
 *
 * **Absent means the task is handed no learner data at all**, which makes
 * minimisation the default rather than an option.
 *
 * It is spelled with the same name the availability conditions already use,
 * deliberately: an author who has met one has met both, and the enforcement
 * is the same. A task that asks at run time for a fact it did not declare
 * finds nothing in the bag, and a task declaring a fact key no
 * AiFactProvider supplies is refused **at registration** rather than at run
 * time, which is what stops the core having to know every fact name.
 *
 * The namesake under Registry\Availability is a different contract over a
 * different context. A provider implementing both aliases one import.
 */
interface RequiresFacts
{
    /**
     * @return list<string> fact provider keys, every one of which must be registered
     */
    public function requiredFacts(): array;
}
