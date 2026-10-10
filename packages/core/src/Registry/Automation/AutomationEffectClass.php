<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Automation;

/**
 * What kind of thing an action changes, declared by the action about itself.
 *
 * This is the vocabulary the approval refusal turns on, and it replaces a scan
 * of seven English words over the action key. The word list could only ever
 * see keys spelled in English by an author who happened to pick one of the
 * seven, so `acme.unlock_module` gated content and saved without a murmur
 * while `grant_availability_probe` was caught by the letters in its name.
 *
 * Three of the eight are the design's "marks, access or credentials", and an
 * action declaring one of those three must also declare
 * RequiresApprovalBeforeEffect or the rule naming it is refused at save. The
 * other five say the action is not in that class, which is a statement the
 * author makes rather than one the core infers.
 */
enum AutomationEffectClass: string
{
    /** Delegates to the notification engine and changes nothing else. */
    case Notifies = 'notifies';

    /** Writes a record the product owns: a tag, a note, a task. */
    case Records = 'records';

    /** Adds or removes a person from an enrolment, cohort or group. */
    case Membership = 'membership';

    /** Opens automation runs and changes no state of its own. */
    case Emits = 'emits';

    /** Hands data to a third party, which cannot be taken back. */
    case External = 'external';

    /** Grants or revokes availability of content. */
    case Access = 'access';

    /** Issues, reissues or revokes a credential. */
    case Credential = 'credential';

    /** Changes a mark, a grade or a completion state. */
    case Mark = 'mark';

    /**
     * The three the design names as human-in-the-loop, so an action declaring
     * one of them may run only as far as a proposed change parked for a named
     * person.
     */
    public function requiresApproval(): bool
    {
        return $this === self::Access || $this === self::Credential || $this === self::Mark;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
