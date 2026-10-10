<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Access\Availability;

/**
 * Where a tree is being authored and evaluated.
 *
 * The same grammar serves four places, which is the largest simplification
 * in this design and also its largest risk: a shared grammar that degrades
 * into a shared syntax with two disjoint vocabularies is worse than two
 * grammars honestly named. Every condition declares which surfaces it
 * supports, the picker offers only those, and a leaf used outside its
 * declared applicability is refused at save rather than mismatched at
 * evaluation.
 *
 * Two of the four store in availability_rules, which is why that table
 * carries a surface discriminator: without it a course cannot hold both a
 * content gate and an enrolment gate, they collide on the unique index, and
 * the save-time refusal has nothing to check against.
 */
enum AvailabilitySurface: string
{
    case ContentGating = 'content_gating';
    case NotificationRule = 'notification_rule';
    case AwardCriteria = 'award_criteria';
    case EnrolmentGating = 'enrolment_gating';

    /**
     * The automation engine's stored tests, which are the same tree again.
     *
     * It stores in the automation rule's own row rather than in
     * availability_rules, so storesInAvailabilityRules() is unchanged. Each
     * shipped condition declares or excludes this surface explicitly rather
     * than being swept in by a blanket inclusion: a leaf that reads a
     * learner's progress means something in a content gate and means
     * something different in a rule that fires on a schedule, and the
     * save-time applicability refusal is only worth anything if every
     * declaration was a decision.
     */
    case Automation = 'automation';

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /** The two surfaces stored in availability_rules. */
    public function storesInAvailabilityRules(): bool
    {
        return $this === self::ContentGating || $this === self::EnrolmentGating;
    }
}
