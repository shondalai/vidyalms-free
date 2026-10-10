<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Ai;

/**
 * A task whose drafts can become a real artefact, and the extension point
 * they would be written through.
 *
 * **Absent means** the task writes nothing through any use case, which is the
 * reading every deterministic check needs and the reason the check family
 * declares nothing here.
 *
 * A structural test refuses a task naming a grading, availability, credential
 * or enrolment use case. That is Moodle's golden rule kept literally: an AI
 * helper proposes, and the shipped domain rules decide.
 */
interface TargetsExtensionPoint
{
    /**
     * The registry the accepted artefact is written into, such as
     * `question_type` or `lesson_type`.
     */
    public function targetExtensionPoint(): string;

    /**
     * The provider key within that extension point, or null when the draft
     * itself names one per candidate.
     */
    public function targetProviderKey(): ?string;
}
