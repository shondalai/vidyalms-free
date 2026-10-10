<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Response;

/**
 * A decoded learner response, carrying the shape it was decoded against.
 *
 * Decoding happens once, in the interaction primitive, and a scoring policy
 * never sees the wire. That is what keeps the QTI Appendix C envelope and
 * the free build's original bare shapes from being two grading paths.
 */
final class ResponseValue
{
    /**
     * @param list<string>|list<float>|array<string, mixed>|string|float|int|bool|null $value
     */
    private function __construct(
        public readonly ResponseShape $shape,
        public readonly mixed $value,
        public readonly bool $isNull,
    ) {
    }

    public static function of(ResponseShape $shape, mixed $value): self
    {
        return new self($shape, $value, false);
    }

    public static function null(ResponseShape $shape): self
    {
        return new self($shape, null, true);
    }

    /**
     * A NULL response, in QTI's sense: absent, or an empty container. Every
     * mapping policy scores 0.0 for it without consulting the map, and
     * correct_answers_only would pay a learner who never opened the item
     * without this clause.
     */
    public function isNullOrEmpty(): bool
    {
        if ($this->isNull || $this->value === null) {
            return true;
        }

        if (\is_array($this->value)) {
            return $this->value === [];
        }

        return $this->value === '';
    }

    /**
     * The container members as identifiers, in submitted order, duplicates
     * preserved. A policy that wants distinct members says so.
     *
     * @return list<string>
     */
    public function members(): array
    {
        if (!\is_array($this->value)) {
            return \is_string($this->value) ? [$this->value] : [];
        }

        $members = [];

        foreach ($this->value as $member) {
            if (\is_string($member)) {
                $members[] = $member;
            } elseif (\is_int($member) || \is_float($member)) {
                $members[] = (string) $member;
            }
        }

        return $members;
    }

    /**
     * Distinct members in first-submitted order. QTI's mapping rule counts a
     * repeated member once: with {A:0, B:1, C:0.5}, {B,B,C} maps to 1.5 and
     * not to 2.5.
     *
     * @return list<string>
     */
    public function distinctMembers(): array
    {
        $seen = [];

        foreach ($this->members() as $member) {
            $seen[$member] = true;
        }

        return array_keys($seen);
    }

    /**
     * The record fields, for a record cardinality response.
     *
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        if (!\is_array($this->value)) {
            return [];
        }

        $fields = [];

        foreach ($this->value as $name => $field) {
            if (\is_string($name)) {
                $fields[$name] = $field;
            }
        }

        return $fields;
    }

    public function asString(): ?string
    {
        return \is_string($this->value) ? $this->value : null;
    }

    public function asBool(): ?bool
    {
        return \is_bool($this->value) ? $this->value : null;
    }

    public function asFloat(): ?float
    {
        if (\is_float($this->value) || \is_int($this->value)) {
            return (float) $this->value;
        }

        return null;
    }
}
