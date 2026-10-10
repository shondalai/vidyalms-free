<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * The action's effect can be undone, and it names what undoes it.
 *
 * Absent means **the effect is not reversible**, and park is therefore the
 * shipped default failure policy for it. That default was previously asserted
 * as "any action with an external side effect" with no field anywhere to read
 * it from, and marking every action reversible made the property unreadable.
 * A webhook has delivered data to a third party the moment it returns 2xx, an
 * emitted event that has already fired other rules cannot be un-emitted, and a
 * model call and its spend cannot be undone: none of those three declares this
 * capability, and that absence is the whole of the fact.
 *
 * The builder reads it twice: it pre-selects park for a step whose action does
 * not declare this, and it says so on the step rather than leaving an author
 * to discover it from a failed run.
 *
 * **The engine probes it nowhere, and that is not an omission.** Park is
 * already the default failure policy for every step, declared or not, on
 * AutomationStepDefinition itself, so the published absent meaning is the
 * shipped behaviour for everybody and probing this would change nothing. The
 * declaration is read by the builder and by the catalogue, which publish
 * `reversible` and `reverses`, and it is stated here so a provider author does
 * not expect the engine to act on it.
 */
interface DeclaresReversal
{
    /**
     * The action key that undoes this one, or null when the effect is
     * reversible by hand but no registered action is its inverse.
     *
     * A pair names each other: unenrol reverses enrol, remove_from_cohort
     * reverses add_to_cohort, clear_tag reverses set_tag.
     */
    public function reverses(): ?string;
}
