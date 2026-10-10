<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * The outcome is parked for a named person AFTER the effect.
 *
 * Absent means the outcome is not parked for anybody. The named person is
 * automation_rule_actions.owner_user_id, defaulting to the rule's armed_by,
 * which is why that column exists rather than a boolean.
 */
interface ProducesReviewItem
{
    /** The language key naming what the parked item is, for the review queue. */
    public function reviewItemKey(): string;
}
