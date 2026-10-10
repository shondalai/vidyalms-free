<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\ScheduleAnchor;

/**
 * The preloaded fact sets this anchor reads.
 *
 * Default when absent: the anchor reads no fact set and answers from the
 * context's own members. It is a capability probed with instanceof rather
 * than a method on the shipped anchor interface, which every third-party
 * anchor already implements.
 *
 * It exists because a condition that dispatches to the anchor registry
 * cannot know less than the anchor it calls. One free anchor read the
 * entitlement fact set while the condition that resolves it declared no fact
 * keys at all, so the anchor was handed a context with nothing in it and
 * answered null: one of the five free anchors was dead on every site, and
 * the same stored leaf resolved or did not depending on whether an unrelated
 * sibling leaf happened to declare that key.
 */
interface AnchorRequiresFacts
{
    /**
     * @return list<string>
     */
    public function requiredFacts(): array;
}
