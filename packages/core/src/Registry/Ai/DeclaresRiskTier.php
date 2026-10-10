<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * How much friction this task's accept step carries.
 *
 * **Absent means** AiRiskTier::Medium, which requires a named reviewer and a
 * recorded decision. The absent default is the middle tier rather than the
 * cheapest one, because a task author who declared nothing has not told
 * anybody the review may be light.
 */
interface DeclaresRiskTier
{
    public function riskTier(): AiRiskTier;
}
