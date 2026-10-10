<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * A task whose result has a declared shape.
 *
 * **Absent means** the result is display only and can never become a draft
 * row. Present with draftKind() returning **null** means the structure is
 * validated and never stored, which is exactly what a learner-facing
 * explanation needs: a schema the mapper checks, and no artefact.
 *
 * The schema is also what the no-mark redline is enforced over. A
 * registration-time check refuses a task whose declared output schema binds a
 * property to a scored or gated field, so a vendor task declaring
 * `{suggested_mark: number}` is refused rather than merely discouraged.
 */
interface DeclaresTaskOutput
{
    /**
     * @return array<string, mixed>
     */
    public function outputSchema(): array;

    /**
     * The kind of draft row this task's output becomes, or null when the
     * output is validated and never stored.
     */
    public function draftKind(): ?string;
}
