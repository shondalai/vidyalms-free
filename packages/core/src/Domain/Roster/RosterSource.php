<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Roster;

/**
 * The bytes of one roster load, already read, keyed by the file name inside the
 * package.
 *
 * The mapper never touches a port. It is handed the files and answers canonical
 * records, which is what makes it an anti-corruption mapper rather than a
 * second importer: the whole of it is testable from a string literal, and a
 * OneRoster file set from the specification's own examples is a fixture rather
 * than a zip on disk.
 *
 * A single-file format uses one entry whose name is the file's own; a OneRoster
 * package uses one entry per CSV, with the enclosing directory already stripped
 * because the specification says a conformant package has none.
 */
final class RosterSource
{
    /** @param array<string, string> $files name inside the package to raw bytes */
    private function __construct(private readonly array $files)
    {
    }

    public static function ofFile(string $name, string $bytes): self
    {
        return new self([$name => $bytes]);
    }

    /**
     * @param array<string, string> $files
     */
    public static function ofPackage(array $files): self
    {
        $named = [];

        foreach ($files as $name => $bytes) {
            // A flat zip with no enclosing directory is what the specification
            // requires, and a package that carries one is a package a real
            // export tool produced anyway. Stripping the leading path here
            // rather than refusing it means the operator's file loads and the
            // conformance statement stays honest, because nothing about the
            // rows changes.
            $flat = substr((string) strrchr('/' . $name, '/'), 1);

            if ($flat !== '') {
                $named[$flat] = $bytes;
            }
        }

        return new self($named);
    }

    /** @return list<string> */
    public function names(): array
    {
        $names = array_keys($this->files);
        sort($names);

        return $names;
    }

    public function has(string $name): bool
    {
        return isset($this->files[$name]);
    }

    /** The bytes of one file, or the empty string when the package has no such file. */
    public function bytes(string $name): string
    {
        return $this->files[$name] ?? '';
    }
}
