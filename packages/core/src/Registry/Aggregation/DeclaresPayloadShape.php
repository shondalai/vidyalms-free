<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Aggregation;

/**
 * The version this aggregation method writes its own parameters in.
 *
 * **It exists because `quizzes.attempt_aggregation_params` held a provider's
 * own settings with no version anywhere near it.** Every newer seam in this
 * product does the opposite: `compliance_requirements.rule_params_version`,
 * `org_catalogue_entries.params_version`, the seat pool policy version and the
 * credential params version are all real columns stamped from the provider's
 * own answer. This one bag was stored bare, so a provider that moved its shape
 * could tell its old rows apart everywhere except here.
 *
 * It is a capability interface probed with `instanceof` rather than a method
 * added to {@see AggregationMethodInterface}, because that interface has
 * shipped and a shipped interface never gains a method.
 *
 * The version is stamped into the stored JSON through
 * {@see \Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape}, on the same terms
 * the compliance seam's rule state uses, so no column is added to a table that
 * already carries rows and nothing downstream sees the envelope. A provider
 * that does not declare this writes no version key at all, which reads back as
 * unversioned: the truthful answer about a bag whose author never said.
 */
interface DeclaresPayloadShape
{
    /** The shape number this provider writes today; one for a first release. */
    public function currentShape(): int;
}
