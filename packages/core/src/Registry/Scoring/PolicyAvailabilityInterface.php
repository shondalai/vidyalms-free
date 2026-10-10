<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

/**
 * A policy that needs a feature this site does not have yet, and says so
 * instead of being silently selectable.
 *
 * A registered policy an author can pick and that can never award a mark is
 * worse than an absent one: the item grades not scored for every attempt,
 * forever, with no explanation reaching the author, and the reason is
 * invisible because the policy resolved perfectly well. Unregistering it is
 * not the answer either, because the key is written into a real
 * questions.scoring_policy column and the registry keeps every key
 * resolvable so a stored row degrades to an explanatory placeholder rather
 * than breaking a course.
 *
 * So a policy declares its own availability. An unavailable one is left out
 * of the offerable set the authoring UI reads, and naming it in a definition
 * is refused at save with the reason, which names the missing feature rather
 * than saying the key is unknown.
 */
interface PolicyAvailabilityInterface
{
    /**
     * False while the feature this policy marks against is absent.
     */
    public function isAvailable(): bool;

    /**
     * What is missing, in the author's words, for the refusal message.
     */
    public function unavailableReason(): string;
}
