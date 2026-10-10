<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;

/**
 * The action costs something a ceiling is checked against.
 *
 * Absent means the action is assumed free and no ceiling is checked for it,
 * which is true of every action the free build ships: nothing free reaches a
 * model, a gateway or a third party.
 *
 * A rule carrying an action that declares this is refused at save when
 * max_ai_runs_per_occurrence or max_ai_runs_per_arming is null, which is what
 * stops an unbounded fan-out being armed by one human act.
 */
interface DeclaresCost
{
    /**
     * The unit this action's cost is denominated in, as the cost record's own
     * vocabulary spells it. Never "credits": cost is denominated in a unit the
     * provider declares.
     */
    public function costUnit(): string;

    /** The declared maximum one execution may cost, in that unit. */
    public function maximumCost(AutomationActionContext $context): float;
}
