<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Notification\Trigger;

/**
 * The subject type words this trigger's occurrences can carry.
 *
 * Absent means the trigger declares no subject types, so nothing is refused
 * against it and the builder shows no filter. Present, it is one half of the
 * save-time applicability check: an action declaring `subject_types` that this
 * trigger can never yield is refused before the rule is stored rather than
 * after it has acted on the wrong person.
 *
 * It is a capability rather than a method on the two trigger interfaces
 * because both are shipped seams and an interface never grows a method after
 * it ships.
 */
interface DeclaresSubjectTypes
{
    /**
     * @return list<string>
     */
    public function subjectTypes(): array;
}
