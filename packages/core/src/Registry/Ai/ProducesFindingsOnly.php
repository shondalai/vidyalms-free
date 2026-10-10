<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * A task that returns findings and nothing else.
 *
 * **Absent means** the task may produce artefacts. Declaring it asserts the
 * result is findings and nothing else, and a task declaring it may not also
 * declare TargetsExtensionPoint: a check that could write is a check that
 * will eventually be wired to auto-approve, which is the outcome the
 * constraints forbid.
 *
 * The rule is asserted over this declared property and never over a key
 * prefix. An earlier form asserted it over tasks whose key begins `check.`,
 * which is a third party's own namespace: a vendor quality check named
 * `acme.duplicate_scan` escaped the assertion entirely and a vendor content
 * task named `check.something` was wrongly constrained by it, on a key
 * ProviderInterface says may never change once shipped.
 */
interface ProducesFindingsOnly
{
}
