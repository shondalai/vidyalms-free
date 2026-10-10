<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Roster;

/**
 * The file itself is wrong, before any row of it is one.
 *
 * A row problem answers "this line could not be used"; this answers "this file
 * could not be read at all", and the two are different outcomes: a run whose
 * file is wrong writes nothing and reports one sentence, rather than forty
 * thousand row problems each saying the same thing.
 *
 * ## It carries a translation key, because the mapper holds no translator
 *
 * The mapper is bytes in and canonical records out: it holds no port, which is
 * what makes the whole of a format testable from a string literal. But the
 * refusal an operator reads has to be in their language, and the rejected
 * organisation column in particular is one the design says must be **rejected
 * by name with a translated error rather than ignored**, so that the operator
 * learns why instead of wondering where their data went.
 *
 * So the mapper throws this with a catalogue key and its parameters, and the
 * importer, which does hold the translator, renders it. The English in
 * getMessage() is the fallback for a context with no translator at all, such as
 * a unit test, and is never what a person on a site reads.
 */
final class RosterFormatException extends \RuntimeException
{
    /**
     * @param array<string, scalar|null> $parameters
     */
    private function __construct(
        public readonly string $translationKey,
        public readonly array $parameters,
        string $fallback,
    ) {
        parent::__construct($fallback);
    }

    /**
     * @param array<string, scalar|null> $parameters
     */
    public static function of(string $translationKey, array $parameters, string $fallback): self
    {
        return new self($translationKey, $parameters, $fallback);
    }

    /**
     * The file names an organisation, which is the one thing a roster file may
     * never do.
     *
     * A member CSV carrying an organisation column would let an org
     * administrator enrol people into another organisation, which is the
     * group-leader leak wearing a spreadsheet. The column is named back to the
     * operator so they can delete it, and the run writes nothing.
     */
    public static function organisationColumn(string $file, string $column): self
    {
        return self::of(
            'vidyalms.roster.error.organisation_column',
            ['file' => $file, 'column' => $column],
            sprintf(
                'The column "%s" in %s names an organisation, and a roster file may not. The organisation'
                    . ' comes from the console the load was started in, so that a file cannot move people'
                    . ' into an organisation the operator does not administer. Delete the column and upload'
                    . ' the file again.',
                $column,
                $file,
            ),
        );
    }
}
