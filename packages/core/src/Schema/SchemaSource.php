<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Schema;

/**
 * A source-level scan of a neutral schema file.
 *
 * A duplicate key cannot be found after the file is loaded, because PHP
 * resolves it first: in an array literal the later entry silently replaces the
 * earlier one, and the array union operator that used to merge the
 * organisation fragments in here silently kept the earlier one. Either way the
 * loaded array is well formed and one declaration has vanished. The only place
 * the collision is still visible is the source, so this reads the file with the
 * tokeniser and reports every key declared more than once inside the same
 * array.
 *
 * It reports at every depth, not only at the table level, and names the path.
 * A column, an index name or a table option replaced by a later duplicate is
 * the same silent loss as a table replaced by a later duplicate, and the
 * default that survives is whichever was written last.
 *
 * The reader understands one shape: a single `return [ ... ];` of short array
 * literals. That assumption is checked rather than trusted, by
 * assertScanCovers(), which compares the keys this scan found at the top level
 * against the keys the loaded array actually has. A file written with
 * `array(...)`, or one whose first `return` belongs to a helper declared above
 * the literal, would otherwise scan as empty and report nothing at all, which
 * is the one answer a refusal like this must never give.
 */
final class SchemaSource
{
    /**
     * Every key declared more than once inside one array, named by its path
     * ("seat_events" for a table, "seat_events.columns.payload" for a column).
     *
     * @return list<string> in order of first declaration
     */
    public static function duplicateTableKeys(string $path): array
    {
        $duplicates = [];

        foreach (self::scan($path)['counts'] as $key => $count) {
            if ($count > 1) {
                $duplicates[] = $key;
            }
        }

        // Only the shallowest collision of a subtree is reported. Two
        // literals under one duplicated key share a path, so every key inside
        // the second "widgets" counts twice as well; naming them all would
        // bury the one declaration that actually vanished.
        return array_values(array_filter(
            $duplicates,
            static function (string $key) use ($duplicates): bool {
                foreach ($duplicates as $other) {
                    if ($other !== $key && str_starts_with($key, $other . '.')) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * The keys the scan read at the top level of the returned array, in order.
     *
     * @return list<string>
     */
    public static function tableKeys(string $path): array
    {
        return self::scan($path)['top'];
    }

    /**
     * Fails the build when a key is declared twice, naming every one.
     */
    public static function assertNoDuplicateTableKeys(string $path): void
    {
        $duplicates = self::duplicateTableKeys($path);

        if ($duplicates === []) {
            return;
        }

        throw new \LogicException(sprintf(
            'Schema file "%s" declares %s more than once; the later declaration silently replaces the earlier one.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $path,
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            '"' . implode('", "', $duplicates) . '"',
        ));
    }

    /**
     * The scan's own assumption, asserted against the loaded array.
     *
     * A silent refusal is worse than no refusal, because the build reports
     * [OK] for a file it never read. So the tables the tokeniser saw must be
     * exactly the tables PHP loaded, duplicates aside.
     *
     * @param array<mixed> $definitions the array the schema file returned
     */
    public static function assertScanCovers(string $path, array $definitions): void
    {
        $scanned = array_values(array_unique(self::tableKeys($path)));
        $loaded = array_map(static fn (int|string $key): string => (string) $key, array_keys($definitions));

        sort($scanned);
        sort($loaded);

        if ($scanned === $loaded) {
            return;
        }

        throw new \LogicException(sprintf(
            'Schema file "%s" is in a shape the duplicate-key scan does not understand: it read %d table keys '
            . 'and the file returns %d. Declare the schema as one "return [ ... ];" of short array literals.',
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            $path,
            \count($scanned),
            \count($loaded),
        ));
    }

    /**
     * @return array{counts: array<string, int>, top: list<string>}
     */
    private static function scan(string $path): array
    {
        $code = @file_get_contents($path);

        if ($code === false) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \LogicException(sprintf('Schema file "%s" cannot be read.', $path));
        }

        $tokens = \PhpToken::tokenize($code);
        $started = false;
        $depth = 0;
        /** @var array<int, string> $trail the key that opened each depth */
        $trail = [];
        /**
         * How many keyless children each depth has opened, so two entries of
         * one list ("indexes" is a list of index literals, and every one of
         * them has a "columns" key) are two different paths rather than one
         * path counted twice.
         *
         * @var array<int, int>
         */
        $anonymous = [0 => -1];
        /** @var array<string, int> $counts */
        $counts = [];
        /** @var list<string> $top */
        $top = [];
        $pending = null;

        foreach ($tokens as $position => $token) {
            if (!$started) {
                if ($token->id === \T_RETURN) {
                    $started = true;
                }

                continue;
            }

            if ($token->text === '[') {
                if ($pending === null) {
                    $anonymous[$depth] = ($anonymous[$depth] ?? -1) + 1;
                    $trail[$depth + 1] = '#' . $anonymous[$depth];
                } else {
                    $trail[$depth + 1] = $pending;
                }

                $depth++;
                $anonymous[$depth] = -1;
                $pending = null;

                continue;
            }

            if ($token->text === ']') {
                unset($trail[$depth], $anonymous[$depth]);
                $depth--;
                $pending = null;

                continue;
            }

            if ($depth < 1 || $token->id !== \T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            if (!self::followedByArrow($tokens, $position)) {
                continue;
            }

            $key = self::literal($token->text);
            $pending = $key;

            $prefix = '';

            for ($level = 2; $level <= $depth; $level++) {
                $prefix .= ($trail[$level] ?? '') . '.';
            }

            $qualified = $prefix . $key;
            $counts[$qualified] = ($counts[$qualified] ?? 0) + 1;

            if ($depth === 1) {
                $top[] = $key;
            }
        }

        return ['counts' => $counts, 'top' => $top];
    }

    /**
     * @param array<int, \PhpToken> $tokens
     */
    private static function followedByArrow(array $tokens, int $position): bool
    {
        $count = \count($tokens);

        for ($i = $position + 1; $i < $count; $i++) {
            if ($tokens[$i]->isIgnorable()) {
                continue;
            }

            return $tokens[$i]->id === \T_DOUBLE_ARROW;
        }

        return false;
    }

    private static function literal(string $text): string
    {
        $quote = $text[0];
        $inner = substr($text, 1, -1);

        if ($quote === "'") {
            return str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
        }

        return $inner;
    }
}
