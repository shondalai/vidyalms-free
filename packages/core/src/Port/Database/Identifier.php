<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Database;

/**
 * The one place the read port decides what a column or an alias may look
 * like.
 *
 * Every column name a Query carries ends up inside a SQL expression rather
 * than in a bound parameter, so every one of them is checked here: the
 * selected columns, the join keys, the conditions, the grouping and the
 * ordering, as well as aggregate columns and result aliases. There is no
 * escape hatch, because an escape hatch is how raw SQL reaches an adapter.
 *
 * The adapters validate again on their own side, which is defence in depth
 * rather than the defence: a third adapter that trusted this docblock and
 * checked nothing would still be safe, which is what the docblock says and
 * now what the code does.
 */
final class Identifier
{
    private const PLAIN = '/^[A-Za-z_][A-Za-z0-9_]*$/D';
    private const QUALIFIED = '/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/D';
    private const QUALIFIED_WILDCARD = '/^[A-Za-z_][A-Za-z0-9_]*\.\*$/D';
    private const RENAMED = '/^([A-Za-z_][A-Za-z0-9_.]*) +AS +([A-Za-z_][A-Za-z0-9_]*)$/iD';

    private function __construct()
    {
    }

    /**
     * One entry of a select list: a column, "*", "alias.*", or a column
     * renamed with "column AS alias".
     *
     * Wildcards and the rename are selections rather than columns, which is
     * why they are separate forms and not a looser column rule: nothing but a
     * select list may carry them.
     */
    public static function selection(string $value): string
    {
        if ($value === '*' || preg_match(self::QUALIFIED_WILDCARD, $value) === 1) {
            return $value;
        }

        if (preg_match(self::RENAMED, $value, $parts) === 1) {
            return self::column($parts[1]) . ' AS ' . self::alias($parts[2]);
        }

        if (preg_match(self::PLAIN, $value) === 1 || preg_match(self::QUALIFIED, $value) === 1) {
            return $value;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw new \InvalidArgumentException(sprintf('"%s" is not a selectable column.', $value));
    }

    /**
     * A table name as a query names it: logical ("courses") or, for a
     * "cms-db:" read, the host's own ("cq_courses"). The adapter still adds
     * its prefix and validates the result; this refuses the shapes that were
     * never a table name in the first place.
     */
    public static function table(string $value): string
    {
        if (preg_match(self::PLAIN, $value) === 1) {
            return $value;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw new \InvalidArgumentException(sprintf('"%s" is not a table name.', $value));
    }

    /**
     * A column: "title" or "c.title". Never "*", never an expression.
     */
    public static function column(string $value): string
    {
        if (preg_match(self::PLAIN, $value) === 1 || preg_match(self::QUALIFIED, $value) === 1) {
            return $value;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw new \InvalidArgumentException(sprintf('"%s" is not a column identifier.', $value));
    }

    /**
     * A result alias, which is always unqualified because it names a column
     * of the result set rather than one of a table.
     */
    public static function alias(string $value): string
    {
        if (preg_match(self::PLAIN, $value) === 1) {
            return $value;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw new \InvalidArgumentException(sprintf('"%s" is not an alias.', $value));
    }
}
