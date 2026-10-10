<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

use Shondalai\VidyaLMS\Core\Registry\Context\AutomationActionContext;

/**
 * The action can be rehearsed without doing anything.
 *
 * Absent means **the action may not appear in the builder at all**, which is a
 * deliberate departure from Uncanny Automator, whose answer to the absence of
 * a rehearsal is to advise a staging site and whose loop documentation states
 * plainly that there is no way to undo what a loop executed. Every other
 * product surveyed ships a rehearsal, and the one place a rehearsal is missing
 * is exactly where the irreversibility warning appears.
 *
 * The dry-run flag itself rides on AutomationActionContext, so one code path
 * serves the preview and the real run. This capability is the author's promise
 * that the path honours it.
 */
interface SupportsDryRun
{
    /**
     * What the action would do, rendered for a person to read, computing no
     * effect. The context's dryRun flag is true when this is called.
     *
     * @return array<string, mixed>
     */
    public function previewFor(AutomationActionContext $context): array;
}
