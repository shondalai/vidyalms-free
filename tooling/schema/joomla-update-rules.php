<?php

/**
 * @package     VidyaLMS
 * @subpackage  Tooling
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 3 or later
 */

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Tooling\Schema;

/**
 * What a Joomla update file has to be, reduced from the CMS code that runs it.
 *
 * Three rules, and every one of them is a defect the review found in the only
 * worked example the repository had:
 *
 * **1. Every statement must survive being run twice.** On the component route
 * this is hygiene: the extension row and its `#__schemas` row survive an
 * update, so `Installer::parseSchemaUpdates` skips every file at or below the
 * recorded version and each file runs once on a healthy site. On the LIBRARY
 * route it is not hygiene, it is the only thing between a release and an
 * aborted install. `LibraryAdapter::checkExtensionInFilesystem` uninstalls the
 * installed library before installing the new one, and `finaliseUninstall`
 * deletes the `#__schemas` row along with the extension row. `storeExtension`
 * then inserts a fresh row with no version, so `parseSchemaUpdates` reads
 * nothing, starts at `0.0.0` and re-runs the WHOLE folder on every single
 * library upgrade. A bare `ALTER TABLE ... ADD COLUMN` therefore succeeds on
 * the upgrade that introduces it and fails on the next one, and a `false` out
 * of `parseSchemaUpdates` is turned into `JLIB_INSTALLER_ABORT_INSTALL_ABORTED`
 * by `InstallerAdapter::parseQueries`. The site is then left holding the older
 * recorded version with the newer files unapplied.
 *
 * The CMS already offers the answer: `Installer::splitSql` understands a
 * `/** CAN FAIL **\/` marker at the end of a statement and swallows that
 * statement's error. A statement is accepted here when it carries the marker,
 * or when it guards itself (`IF NOT EXISTS`, `IF EXISTS`, `INSERT IGNORE`,
 * `ON DUPLICATE KEY UPDATE`), or when it is not DDL at all, because an
 * `UPDATE` or a `DELETE` that matches nothing the second time is a no-op
 * rather than an error.
 *
 * **2. No PREPARE, EXECUTE or DEALLOCATE.** The obvious way to write a guarded
 * ALTER on MySQL, which has no `ADD COLUMN IF NOT EXISTS`, is to build the
 * statement into a variable and PREPARE it. Joomla's mysqli driver cannot run
 * it: the server answers "This command is not supported in the prepared
 * statement protocol yet", and half the sites in the world run that driver.
 *
 * **3. No `#__` inside a quoted literal.** `DatabaseDriver::replacePrefix`
 * walks the string and replaces the prefix token only OUTSIDE quotes, so
 * `WHERE TABLE_NAME = '#__vidyalms_x'` reaches the server as the literal three
 * characters `#__`, matches no row, and the guard built on it silently decides
 * the work is already done, or is not needed, or whatever the wrong answer
 * happens to be. It never errors, which is what makes it worth refusing at
 * build time.
 */
final class JoomlaUpdateRules
{
    /**
     * The marker Installer::splitSql understands, spelled out here rather than
     * imported: this file runs in a packager and in a rehearsal, neither of
     * which has the CMS on the include path.
     */
    public const CAN_FAIL_MARKER = '/** CAN FAIL **/';

    private function __construct()
    {
    }

    /**
     * The statements a Joomla installer would actually execute out of one file.
     *
     * Comments come out first, which is what makes a marker-only file answer
     * the empty list. Everything else is Installer::splitSql reduced to the
     * split: the marker is left attached, because whether a statement carries
     * it is the question rule 1 asks.
     *
     * @return list<string>
     */
    public static function statementsIn(string $sql): array
    {
        $withoutComments = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

        $statements = [];

        foreach (explode(';', $withoutComments) as $part) {
            $statement = trim($part, "; \n\r\t");

            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * Whether one statement declares itself allowed to fail.
     */
    public static function carriesCanFailMarker(string $statement): bool
    {
        return str_ends_with(strtoupper(rtrim($statement)), strtoupper(self::CAN_FAIL_MARKER));
    }

    /**
     * DatabaseDriver::replacePrefix, reduced to the property that matters: the
     * token is replaced outside quoted literals and left alone inside them.
     *
     * Faithful enough to answer the question a rehearsal asks of it, which is
     * whether the table name a statement builds reaches the server with the
     * site prefix on it. Backslash escaping inside a literal is honoured,
     * because that is how a literal ends.
     *
     * A backquote is not a quote here, and that is the CMS behaviour rather
     * than a simplification: `DatabaseDriver::replacePrefix` looks for `'` and
     * `"` only, so an identifier written `` `#__vidyalms_x` `` is rewritten and
     * the string `'#__vidyalms_x'` is not. That asymmetry is the whole of the
     * defect rule 3 refuses.
     */
    public static function replacePrefix(string $sql, string $prefix, string $token = '#__'): string
    {
        $out = '';
        $quote = '';
        $length = \strlen($sql);
        $tokenLength = \strlen($token);

        for ($index = 0; $index < $length; $index++) {
            $character = $sql[$index];

            if ($quote !== '') {
                $out .= $character;

                if ($character === '\\' && $index + 1 < $length) {
                    $out .= $sql[++$index];

                    continue;
                }

                if ($character === $quote) {
                    $quote = '';
                }

                continue;
            }

            if ($character === "'" || $character === '"') {
                $quote = $character;
                $out .= $character;

                continue;
            }

            if (substr($sql, $index, $tokenLength) === $token) {
                $out .= $prefix;
                $index += $tokenLength - 1;

                continue;
            }

            $out .= $character;
        }

        return $out;
    }

    /**
     * Everything wrong with one update file, as sentences naming the file.
     *
     * An empty list is the answer a shippable file gives. The floor marker
     * carries no statement at all and therefore has nothing to be wrong with,
     * which is why it is not special-cased.
     *
     * @return list<string>
     */
    public static function problemsIn(string $sql, string $label): array
    {
        $problems = [];

        foreach (self::statementsIn($sql) as $position => $statement) {
            $where = $label . ' statement ' . ($position + 1);
            $upper = strtoupper($statement);

            if (preg_match('/^\s*(PREPARE|EXECUTE|DEALLOCATE)\b/', $upper) === 1) {
                $problems[] = $where . ' uses a prepared statement, which Joomla\'s mysqli driver cannot run'
                    . ' ("this command is not supported in the prepared statement protocol yet").';

                // One refusal per statement is enough to send the author back
                // to it; the repeatability question below is moot for a
                // statement that cannot run at all.
                continue;
            }

            foreach (self::quotedLiterals($statement) as $literal) {
                if (str_contains($literal, '#__')) {
                    $problems[] = $where . ' hides #__ inside the quoted literal ' . $literal
                        . ', which DatabaseDriver::replacePrefix leaves alone, so the statement names a table'
                        . ' no site has.';
                }
            }

            if (!self::isRepeatable($statement)) {
                $problems[] = $where . ' is unguarded DDL: add ' . self::CAN_FAIL_MARKER
                    . ' before its semicolon, or an IF (NOT) EXISTS clause. A Joomla library update re-runs'
                    . ' this whole folder from 0.0.0, so a statement that fails the second time aborts the'
                    . ' next upgrade.';
            }
        }

        return $problems;
    }

    /**
     * Whether running this statement a second time is a no-op rather than an
     * error.
     */
    public static function isRepeatable(string $statement): bool
    {
        if (self::carriesCanFailMarker($statement)) {
            return true;
        }

        $upper = strtoupper(preg_replace('/\s+/', ' ', trim($statement)) ?? '');

        // Not DDL: a second run matches nothing and changes nothing.
        if (preg_match('/^(UPDATE|DELETE|SELECT|SET|DO|REPLACE)\b/', $upper) === 1) {
            return true;
        }

        if (preg_match('/^INSERT\s+IGNORE\b/', $upper) === 1 || str_contains($upper, 'ON DUPLICATE KEY UPDATE')) {
            return true;
        }

        // A plain INSERT is DDL-shaped for this purpose: the second run either
        // duplicates the row or trips a unique index.
        if (preg_match('/^INSERT\b/', $upper) === 1) {
            return false;
        }

        return str_contains($upper, 'IF NOT EXISTS') || str_contains($upper, 'IF EXISTS');
    }

    /**
     * The single and double quoted literals of one statement, quotes included.
     *
     * Backquoted identifiers are not literals and are not returned: `#__x` is
     * exactly the form replacePrefix is meant to rewrite.
     *
     * @return list<string>
     */
    public static function quotedLiterals(string $statement): array
    {
        $literals = [];
        $length = \strlen($statement);

        for ($index = 0; $index < $length; $index++) {
            $character = $statement[$index];

            if ($character !== "'" && $character !== '"') {
                continue;
            }

            $literal = $character;
            $index++;

            for (; $index < $length; $index++) {
                $literal .= $statement[$index];

                if ($statement[$index] === '\\' && $index + 1 < $length) {
                    $literal .= $statement[++$index];

                    continue;
                }

                if ($statement[$index] === $character) {
                    break;
                }
            }

            $literals[] = $literal;
        }

        return $literals;
    }
}
