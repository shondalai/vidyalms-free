<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Availability;

/**
 * The leaf may set exhausted on a BLOCKED outcome. Default when absent: an
 * exhausted flag from a provider that does not declare it is ignored and
 * logged, exactly as an undeclared NEVER is downgraded.
 *
 * Exhaustion is a fact only the provider holds and is not derivable from the
 * capability set. The tempting structural test, "a blocking leaf that
 * declares neither NamesOpensAt nor RequiresLearnerAction", is wrong: a
 * completion leaf declares neither and is satisfied by the learner doing the
 * work, so that test would drop every prerequisite in the product out of
 * every denominator.
 */
interface DeclaresExhaustion
{
}
