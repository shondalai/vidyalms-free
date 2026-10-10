<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Roster;

/**
 * One person's place in one class, as the source states it.
 *
 * It exists as a canonical shape now, in the wave that builds the mapper seam,
 * rather than in the wave that first writes one. The reason is the reason the
 * shapes exist at all: a later format's mapper has to have something to map
 * onto, and a shape invented later by whoever needs it first is a shape the
 * earlier mappers do not produce.
 *
 * ## The free build reads these and writes none of them
 *
 * That is a conformance statement and not an omission. A OneRoster `class` is a
 * section of a `course` inside an academic session, and mapping one onto
 * something here means deciding whether it is a course, a cohort or a course
 * inside a cohort. The design defers that decision (classes are read "only
 * where a class maps onto a course or a cohort"), so the free roster importers
 * produce these records, count them, and report each one as skipped with the
 * reason, which is what {@see RosterConformance} publishes in the same run's
 * first log line.
 *
 * Producing and reporting them rather than ignoring the file is the honest
 * half: an operator whose export carries four thousand enrolments is told that
 * four thousand enrolments were read and not written, instead of finding a
 * roster that loaded cleanly and enrolled nobody.
 */
final class CourseAssignmentRecord
{
    private function __construct(
        public readonly string $externalId,
        public readonly string $personExternalId,
        public readonly string $classExternalId,
        public readonly ?string $orgExternalId,
        public readonly ?string $roleToken,
        public readonly string $status,
        public readonly bool $primary,
        public readonly ?string $beginsAt,
        public readonly ?string $endsAt,
    ) {
    }

    public static function of(
        string $externalId,
        string $personExternalId,
        string $classExternalId,
        ?string $orgExternalId = null,
        ?string $roleToken = null,
        string $status = OrgMembershipRecord::STATUS_ACTIVE,
        bool $primary = false,
        ?string $beginsAt = null,
        ?string $endsAt = null,
    ): self {
        return new self(
            trim($externalId),
            trim($personExternalId),
            trim($classExternalId),
            self::text($orgExternalId),
            self::text($roleToken),
            $status,
            $primary,
            self::text($beginsAt),
            self::text($endsAt),
        );
    }

    private static function text(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);

        return $value === '' ? null : $value;
    }
}
