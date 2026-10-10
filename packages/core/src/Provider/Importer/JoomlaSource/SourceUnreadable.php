<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Importer\JoomlaSource;

/**
 * One table of a foreign product could not be read the way this importer
 * expects it.
 *
 * It carries the table and the column list rather than only the driver's
 * message, because that pair is the whole contract with a schema this project
 * does not own: the three Joomla source importers are written from published
 * documentation and are unverified against a real install, so "the shape has
 * moved" is the expected failure and it has to name what was expected.
 *
 * It is a refusal of one table, never of a run. The importer catches it,
 * records the table it could not read, and carries on with the ones it can:
 * a Guru site whose quiz tables were renamed still gets its courses across.
 */
final class SourceUnreadable extends \RuntimeException
{
    /**
     * @param list<string> $columns the columns the read asked for
     */
    private function __construct(
        public readonly string $table,
        public readonly array $columns,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param list<string> $columns
     */
    public static function of(string $table, array $columns, \Throwable $failure): self
    {
        return new self($table, $columns, sprintf(
            'The table "%s" could not be read as (%s): %s',
            $table,
            $columns === [] ? 'no columns' : implode(', ', $columns),
            trim($failure->getMessage()) === '' ? $failure::class : trim($failure->getMessage()),
        ), $failure);
    }

    /**
     * The sentence a job log carries for it: what was expected, and what a
     * site owner should do about it.
     */
    public function explanation(): string
    {
        return $this->getMessage()
            . ' This importer was written from the product\'s published schema documentation and has not been'
            . ' verified against a real install, so a renamed table or column here is expected rather than'
            . ' surprising. Nothing from that table was imported.';
    }
}
