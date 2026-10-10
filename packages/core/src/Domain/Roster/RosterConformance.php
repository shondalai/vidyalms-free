<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Roster;

/**
 * What a roster mapper reads and what it deliberately does not, as a value the
 * product can show rather than a sentence in a document.
 *
 * **Conformance is a stated non-goal.** Reading a OneRoster 1.2 CSV file set is
 * not the same as being a certified OneRoster consumer, and the difference is
 * one a customer has to be able to see before they upload anything. So the
 * subset is a value object: the importer writes it into the first line of every
 * run's log, the importer catalogue route publishes it, and a screen can render
 * it beside the format name.
 *
 * Saying it in code rather than only in prose is what prevents the failure the
 * design names: an implementation that half-implements the rest of the standard
 * on the way past, one file at a time, until nobody can say what the product
 * claims.
 */
final class RosterConformance
{
    /**
     * @param list<string> $reads    the objects this mapper genuinely consumes
     * @param list<string> $ignores  what it reads past on purpose
     */
    private function __construct(
        public readonly string $standard,
        public readonly array $reads,
        public readonly array $ignores,
        public readonly string $statement,
    ) {
    }

    /**
     * @param list<string> $reads
     * @param list<string> $ignores
     */
    public static function of(string $standard, array $reads, array $ignores, string $statement): self
    {
        return new self($standard, array_values($reads), array_values($ignores), $statement);
    }

    /** The one line an importer writes at the top of its own job log. */
    public function line(): string
    {
        return sprintf(
            '%s. Reads: %s. Ignores: %s. %s',
            $this->standard,
            $this->reads === [] ? 'nothing' : implode(', ', $this->reads),
            $this->ignores === [] ? 'nothing' : implode(', ', $this->ignores),
            $this->statement,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'standard' => $this->standard,
            'reads' => $this->reads,
            'ignores' => $this->ignores,
            'statement' => $this->statement,
        ];
    }
}
