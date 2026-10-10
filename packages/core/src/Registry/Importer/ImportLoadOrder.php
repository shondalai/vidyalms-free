<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Importer;

/**
 * The order a multi-part source is loaded in, stated once and enforced by the
 * runner rather than by each mapper.
 *
 * OneRoster makes referential integrity a precondition of the whole file set
 * (a sourcedId referenced anywhere must be defined somewhere) and then states
 * that there is no preferred file ordering inside the zip, so the consumer
 * chooses. If each mapper chooses, each mapper chooses differently, and the
 * mapper that loads enrolments before people discovers it on a customer's
 * roster rather than in a test.
 *
 * So the order is here:
 *
 * 1. organisations, parents before children
 * 2. units, likewise
 * 3. people
 * 4. memberships
 * 5. roles
 * 6. releases, which is catalogue entries and the enrolments that follow them
 * 7. seats
 *
 * A row naming a parent that no earlier stage produced is a
 * RowProblem::CODE_REFERENTIAL_MISS naming the parent, skipped and never
 * retried. That is the whole reason the order is fixed: with it, a miss is a
 * fact about the file; without it, a miss is a fact about which stage happened
 * to run first, and the same file loads differently twice.
 */
final class ImportLoadOrder
{
    public const STAGE_ORGANISATIONS = 'organisations';
    public const STAGE_UNITS = 'units';
    public const STAGE_PEOPLE = 'people';
    public const STAGE_MEMBERSHIPS = 'memberships';
    public const STAGE_ROLES = 'roles';
    public const STAGE_RELEASES = 'releases';
    public const STAGE_SEATS = 'seats';

    /**
     * The whole order, and the only order.
     *
     * @var list<string>
     */
    public const STAGES = [
        self::STAGE_ORGANISATIONS,
        self::STAGE_UNITS,
        self::STAGE_PEOPLE,
        self::STAGE_MEMBERSHIPS,
        self::STAGE_ROLES,
        self::STAGE_RELEASES,
        self::STAGE_SEATS,
    ];

    private function __construct()
    {
    }

    /**
     * The stages a source carries, in load order, deduplicated.
     *
     * The declared order is discarded rather than respected, which is the
     * point: a mapper that declares enrolments before people is not making a
     * choice, it is making a mistake, and honouring the declaration would hide
     * it until the first file whose enrolments reference a person the same
     * file creates.
     *
     * @param list<string> $stages
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException on a stage this order does not name
     */
    public static function sort(array $stages): array
    {
        foreach ($stages as $stage) {
            if (!\in_array($stage, self::STAGES, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'An import stage is one of %s; "%s" is none of them. A source with a part that is none of'
                        . ' these loads it inside the stage it belongs to rather than adding an eighth.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    implode(', ', self::STAGES),
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $stage,
                ));
            }
        }

        $ordered = [];

        foreach (self::STAGES as $stage) {
            if (\in_array($stage, $stages, true)) {
                $ordered[] = $stage;
            }
        }

        return $ordered;
    }

    /** Whether the first stage is loaded before the second. */
    public static function precedes(string $stage, string $other): bool
    {
        $first = array_search($stage, self::STAGES, true);
        $second = array_search($other, self::STAGES, true);

        return \is_int($first) && \is_int($second) && $first < $second;
    }
}
