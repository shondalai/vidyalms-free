<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Automation;

/**
 * Whether a subject already inside a rule may enter it again.
 *
 * A declared field from version one, because retrofitting re-entry changes
 * every stored rule payload. The default follows HubSpot's rule that a record
 * already enrolled cannot re-enter until it completes and that re-enrolment is
 * an explicit opt-in.
 */
enum ReentryMode: string
{
    case Never = 'never';
    case OnNewOccurrence = 'on_new_occurrence';
    case Always = 'always';

    /**
     * Whether a second run for the same rule, subject and occurrence is
     * allowed at all.
     *
     * never and on_new_occurrence give the same answer here and differ one
     * level up: the occurrence key is part of the run's unique index, so
     * on_new_occurrence is expressed by the occurrence changing rather than by
     * a second row for the same occurrence. always is the only mode that
     * wants a distinct run inside one occurrence, and it gets it by carrying
     * the attempt ordinal in the occurrence key rather than by dropping the
     * index.
     */
    public function allowsRepeatWithinOneOccurrence(): bool
    {
        return $this === self::Always;
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
