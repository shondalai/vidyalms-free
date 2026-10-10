<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Shared;

/**
 * The ceilings every paged list obeys, as one injected object rather than as
 * constants on the use cases that happen to enforce them.
 *
 * These numbers are judgements about a site, not facts about the product. A
 * university with twenty thousand enrolments on one course cannot use the
 * name sort at all while the ceiling is a constant in a core file, and a
 * small site on shared hosting cannot lower any of them. They arrive from
 * the adapter's options array exactly as verification_base_url and
 * expiry_sweep_schedule already do, so a site owner changes them without a
 * fork and a package can hand the kernel a policy of its own.
 *
 * The defaults are what the free build shipped with, so a host that passes
 * nothing behaves exactly as before.
 */
final class ListingPolicy
{
    /** The largest page any management list will answer. */
    public const DEFAULT_MAX_PER_PAGE = 100;

    /**
     * How many rows a sort by learner name may name at once. The order lives
     * in the CMS, so the whole matching set has to be named before the page
     * can be cut: that is bounded work rather than free work, and above this
     * it is refused rather than answered slowly.
     */
    public const DEFAULT_NAME_SORT_CEILING = 5000;

    /** How many CMS accounts a search term may resolve to. */
    public const DEFAULT_SEARCH_MATCH_LIMIT = 500;

    private function __construct(
        public readonly int $maxPerPage,
        public readonly int $nameSortCeiling,
        public readonly int $searchMatchLimit,
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            self::DEFAULT_MAX_PER_PAGE,
            self::DEFAULT_NAME_SORT_CEILING,
            self::DEFAULT_SEARCH_MATCH_LIMIT,
        );
    }

    /**
     * Reads whatever the host put under the "listing" option. A missing or
     * malformed value takes the default rather than failing a boot: a
     * mistyped setting must never take a site's API down.
     *
     * @param mixed $option
     */
    public static function fromOption($option): self
    {
        if (!\is_array($option)) {
            return self::defaults();
        }

        return new self(
            self::positive($option['max_per_page'] ?? null, self::DEFAULT_MAX_PER_PAGE),
            self::positive($option['name_sort_ceiling'] ?? null, self::DEFAULT_NAME_SORT_CEILING),
            self::positive($option['search_match_limit'] ?? null, self::DEFAULT_SEARCH_MATCH_LIMIT),
        );
    }

    /**
     * @param mixed $value
     */
    private static function positive($value, int $default): int
    {
        if (\is_int($value) && $value > 0) {
            return $value;
        }

        if (\is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }

        return $default;
    }
}
