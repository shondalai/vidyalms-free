<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Roster;

/**
 * `manifest.csv`, which is a property and value file and not a header and row
 * file.
 *
 * This is the single most commonly misread part of the OneRoster CSV binding
 * and it is worth stating in code rather than in a comment. The file has two
 * columns, `propertyName` and `value`, and every line is one property:
 *
 *     propertyName,value
 *     manifest.version,1.0
 *     oneroster.version,1.2
 *     file.orgs,bulk
 *     file.users,bulk
 *     file.enrollments,delta
 *     file.academicSessions,absent
 *     source.systemName,Example SIS
 *
 * An implementation that reads it as a header and rows gets one row of nonsense
 * and then guesses the processing mode, which is the failure this class exists
 * to make impossible.
 *
 * ## Processing mode is per file
 *
 * `file.<name>` carries `absent`, `bulk` or `delta`, so a package may
 * legitimately mix a bulk `users` file with a delta `enrollments` file, **and
 * the runner honours that per file rather than per package.** The two modes are
 * different contracts over the same columns:
 *
 * - **bulk**: `status` and `dateLastModified` must not carry values, and every
 *   present row is authoritative.
 * - **delta**: both must be populated, and `status` is `active` or
 *   `tobedeleted`.
 *
 * A file the manifest calls `absent` must not be in the package, and a file
 * with no data rows is not permitted: it is declared absent and omitted. Both
 * are checked, because a package that says one thing and carries another is a
 * package whose row counts nobody can trust.
 */
final class RosterManifest
{
    public const MODE_ABSENT = 'absent';
    public const MODE_BULK = 'bulk';
    public const MODE_DELTA = 'delta';

    /** @var list<string> */
    public const MODES = [self::MODE_ABSENT, self::MODE_BULK, self::MODE_DELTA];

    public const FILE_NAME = 'manifest.csv';

    /** The property prefix that declares one file's processing mode. */
    public const FILE_PREFIX = 'file.';

    /**
     * @param array<string, string> $properties every property, verbatim
     * @param array<string, string> $modes      the short file name to its declared mode
     * @param list<string>          $problems   what the manifest itself got wrong
     */
    private function __construct(
        public readonly array $properties,
        public readonly array $modes,
        public readonly array $problems,
    ) {
    }

    /**
     * Reads a manifest out of its rows, which the caller has already split with
     * the format's own CSV rules.
     *
     * @param list<list<string>> $rows
     */
    public static function fromRows(array $rows): self
    {
        $properties = [];
        $modes = [];
        $problems = [];

        foreach ($rows as $index => $row) {
            $name = trim($row[0] ?? '');
            $value = trim($row[1] ?? '');

            if ($name === '' || $name === 'propertyName') {
                continue;
            }

            $properties[$name] = $value;

            if (!str_starts_with($name, self::FILE_PREFIX)) {
                continue;
            }

            $file = substr($name, \strlen(self::FILE_PREFIX));

            if (!\in_array($value, self::MODES, true)) {
                $problems[] = sprintf(
                    'Line %d declares "%s" for %s. A file is absent, bulk or delta and nothing else, and the'
                        . ' mode decides whether status and dateLastModified are required or forbidden.',
                    $index + 1,
                    $value,
                    $name,
                );

                continue;
            }

            $modes[$file] = $value;
        }

        foreach (['manifest.version' => '1.0', 'oneroster.version' => '1.2'] as $property => $expected) {
            $seen = $properties[$property] ?? '';

            if ($seen === '') {
                $problems[] = sprintf('The manifest does not declare %s, which it must.', $property);

                continue;
            }

            if ($seen !== $expected) {
                $problems[] = sprintf(
                    'The manifest declares %s = %s. This importer reads %s, and reading a file set of a'
                        . ' different version as if it were this one is how a column silently changes meaning.',
                    $property,
                    $seen,
                    $expected,
                );
            }
        }

        return new self($properties, $modes, $problems);
    }

    /** An empty manifest, for the format that has none. */
    public static function none(): self
    {
        return new self([], [], []);
    }

    /**
     * The mode declared for one file, defaulting to absent.
     *
     * Absent is the right default rather than bulk: a file the manifest never
     * mentions is a file the package does not claim, and treating it as
     * authoritative would let a stray CSV in a zip rewrite a roster.
     */
    public function modeOf(string $file): string
    {
        return $this->modes[$file] ?? self::MODE_ABSENT;
    }

    public function isDelta(string $file): bool
    {
        return $this->modeOf($file) === self::MODE_DELTA;
    }

    public function isBulk(string $file): bool
    {
        return $this->modeOf($file) === self::MODE_BULK;
    }

    /**
     * The files the manifest says are present, in declaration order.
     *
     * @return list<string>
     */
    public function declaredFiles(): array
    {
        return array_keys(array_filter(
            $this->modes,
            static fn (string $mode): bool => $mode !== self::MODE_ABSENT,
        ));
    }

    /** The optional source system name, for the job log. */
    public function systemName(): string
    {
        return $this->properties['source.systemName'] ?? '';
    }
}
