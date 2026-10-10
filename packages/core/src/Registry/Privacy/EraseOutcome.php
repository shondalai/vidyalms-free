<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Privacy;

/**
 * The receipt an erasure hands back: what went, what was rewritten, what
 * survived and why, and what was deliberately not touched.
 *
 * The shipped receipt was three count maps and a list of free-text warnings, and
 * two of the things the design requires a receipt to show could not be expressed
 * in it. The first is a **retention basis per record class**: a receipt that says
 * "seat_events: 4 retained" and nothing else is a receipt an auditor cannot act
 * on, and a warning string saying no basis is claimed is worse, because it
 * records that the product knows the rows are there and has no answer for them.
 * The second is the **untouched-because-another-organisation count**, which is
 * the evidence that the tenancy bound held. A learner active in two
 * organisations and erased by one keeps every row the other paid for, and the
 * receipt shows that happening rather than asserting it did.
 *
 * It is built through the accumulating methods rather than assembled at the end,
 * because an erasure is a sequence of writes and a receipt computed afterwards
 * is a receipt reconstructed from what the writer remembers.
 */
final class EraseOutcome
{
    /**
     * @param array<string, int>                                        $erased
     * @param array<string, int>                                        $pseudonymised
     * @param array<string, int>                                        $retained
     * @param array<string, int>                                        $untouched
     * @param array<string, array{basis: string, statute_ref: ?string}> $bases
     * @param list<string>                                              $warnings
     */
    private function __construct(
        private readonly array $erased,
        private readonly array $pseudonymised,
        private readonly array $retained,
        private readonly array $untouched,
        private readonly array $bases,
        private readonly array $warnings,
    ) {
    }

    public static function begin(): self
    {
        return new self([], [], [], [], [], []);
    }

    /** Rows this erasure deleted outright. */
    public function erased(string $recordClass, int $count): self
    {
        return new self(
            self::add($this->erased, $recordClass, $count),
            $this->pseudonymised,
            $this->retained,
            $this->untouched,
            $this->bases,
            $this->warnings,
        );
    }

    /**
     * Rows this erasure kept and stripped of the subject's identity.
     *
     * A pseudonymisation is always accompanied by a basis, because a row that
     * survives is a row somebody has to justify: pseudonymising without naming
     * a basis is the same shrug as retaining without one, one step quieter.
     */
    public function pseudonymised(
        string $recordClass,
        int $count,
        string $basis = RetentionBasis::LEGAL_OBLIGATION,
        ?string $statuteRef = null,
    ): self {
        return new self(
            $this->erased,
            self::add($this->pseudonymised, $recordClass, $count),
            $this->retained,
            $this->untouched,
            self::withBasis($this->bases, $recordClass, $basis, $statuteRef),
            $this->warnings,
        );
    }

    /** Rows kept whole, with the basis that keeps them. */
    public function retained(
        string $recordClass,
        int $count,
        string $basis,
        ?string $statuteRef = null,
    ): self {
        return new self(
            $this->erased,
            $this->pseudonymised,
            self::add($this->retained, $recordClass, $count),
            $this->untouched,
            self::withBasis($this->bases, $recordClass, $basis, $statuteRef),
            $this->warnings,
        );
    }

    /**
     * Rows this erasure deliberately did not touch because they belong to
     * another organisation or to the learner themselves.
     *
     * This is the tenancy bound made visible. It is not a retention: nobody is
     * keeping these rows on a basis, because they were never this
     * organisation's to erase.
     */
    public function untouchedElsewhere(string $recordClass, int $count): self
    {
        return new self(
            $this->erased,
            $this->pseudonymised,
            $this->retained,
            self::add($this->untouched, $recordClass, $count),
            $this->bases,
            $this->warnings,
        );
    }

    public function warning(string $warning): self
    {
        return new self(
            $this->erased,
            $this->pseudonymised,
            $this->retained,
            $this->untouched,
            $this->bases,
            array_values(array_unique([...$this->warnings, $warning])),
        );
    }

    /** @return array<string, int> */
    public function erasedCounts(): array
    {
        return $this->erased;
    }

    /** @return array<string, int> */
    public function untouchedCounts(): array
    {
        return $this->untouched;
    }

    /** The basis recorded for one record class, or `none` when nothing claimed one. */
    public function basisFor(string $recordClass): string
    {
        return $this->bases[$recordClass]['basis'] ?? RetentionBasis::NONE;
    }

    /**
     * Every record class this receipt kept with no basis behind it.
     *
     * @return list<string>
     */
    public function unjustified(): array
    {
        $classes = array_unique([...array_keys($this->retained), ...array_keys($this->pseudonymised)]);

        return array_values(array_filter(
            $classes,
            fn (string $class): bool => $this->basisFor($class) === RetentionBasis::NONE,
        ));
    }

    /**
     * The shipped receipt shape, so a caller written against the array keeps
     * reading it unchanged, plus the two things it could not say.
     *
     * The four original keys keep their meaning and their position. That is
     * deliberate: the array is what
     * {@see PrivacyContributorInterface::eraseFor()} returns on the published
     * contract, and widening a payload is additive while renaming a key is not.
     *
     * @return array{erased: array<string, int>, pseudonymised: array<string, int>,
     *     retained: array<string, int>, warnings: list<string>,
     *     untouched_other_organisation: array<string, int>,
     *     retention_bases: array<string, array{basis: string, statute_ref: ?string}>}
     */
    public function toArray(): array
    {
        return [
            'erased' => $this->erased,
            'pseudonymised' => $this->pseudonymised,
            'retained' => $this->retained,
            'warnings' => $this->warnings,
            'untouched_other_organisation' => $this->untouched,
            'retention_bases' => $this->bases,
        ];
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private static function add(array $counts, string $recordClass, int $count): array
    {
        $counts[$recordClass] = ($counts[$recordClass] ?? 0) + max(0, $count);

        return $counts;
    }

    /**
     * @param array<string, array{basis: string, statute_ref: ?string}> $bases
     *
     * @return array<string, array{basis: string, statute_ref: ?string}>
     */
    private static function withBasis(array $bases, string $recordClass, string $basis, ?string $ref): array
    {
        $bases[$recordClass] = [
            'basis' => RetentionBasis::of($basis),
            'statute_ref' => $ref === '' ? null : $ref,
        ];

        return $bases;
    }
}
