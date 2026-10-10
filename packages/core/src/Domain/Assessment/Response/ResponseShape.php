<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Response;

/**
 * (cardinality, base type) plus a record signature when the cardinality is
 * record. This is the taxonomy scoring policies bind to, and it is what the
 * generic fallback renderer keys on when a provider is gone, which is why it
 * is mirrored into the real questions.response_shape column: every one of
 * those readers has to work with the provider uninstalled, so none of them
 * may ask the provider.
 *
 * Policies bind to shapes, never to type keys. That is what makes one
 * per_target implementation serve matching, categorization, gap match, drag
 * onto image and graphic association at once.
 */
final class ResponseShape
{
    /** @var list<ResponseField> */
    public readonly array $fields;

    /**
     * @param list<ResponseField> $fields
     */
    private function __construct(
        public readonly Cardinality $cardinality,
        public readonly BaseType $baseType,
        array $fields = [],
    ) {
        if ($cardinality !== Cardinality::Record && $fields !== []) {
            throw new \InvalidArgumentException('Only a record response shape carries a field signature.');
        }

        $this->fields = $fields;
    }

    public static function of(Cardinality $cardinality, BaseType $baseType): self
    {
        return new self($cardinality, $baseType);
    }

    /**
     * @param list<ResponseField> $fields
     */
    public static function record(array $fields): self
    {
        if ($fields === []) {
            throw new \InvalidArgumentException('A record response shape needs a field signature.');
        }

        return new self(Cardinality::Record, BaseType::Identifier, $fields);
    }

    /**
     * The wildcard record, legal only as a policy's shape binding and never
     * on a question row. A policy that binds to it applies to every record
     * response; a policy that needs a particular signature checks it in
     * validateParams.
     */
    public static function anyRecord(): self
    {
        return new self(Cardinality::Record, BaseType::Identifier, []);
    }

    /**
     * The stored form: "multiple:identifier", or
     * "record(g1:single:identifier,g2:single:identifier)" for a record.
     *
     * Kept inside questions.response_shape, which is string:30, so a record
     * signature longer than the column is truncated to its head. The head is
     * the part every reader keys on; the fields are a refinement the
     * provider still owns.
     */
    public function key(): string
    {
        if ($this->cardinality !== Cardinality::Record) {
            return $this->cardinality->value . ':' . $this->baseType->value;
        }

        return 'record(' . implode(',', array_map(
            static fn (ResponseField $field): string => $field->signature(),
            $this->fields,
        )) . ')';
    }

    /**
     * What goes in the column: the key, clipped to the column width at a
     * field boundary so a truncated value is still parseable.
     */
    public function columnValue(): string
    {
        $key = $this->key();

        if (\strlen($key) <= 30) {
            return $key;
        }

        return 'record';
    }

    public function isRecord(): bool
    {
        return $this->cardinality === Cardinality::Record;
    }

    /**
     * Whether a wire value could be a response of this shape at all.
     *
     * This is the check that makes "values are self-describing" mean
     * something. A type is handed whatever the client posted and decoding is
     * deliberately lenient, so without it every interaction read every other
     * interaction's answer: a single choice item was recorded as answered on
     * a list of directed pairs, on a file record, on a boolean and on a
     * record of gaps, and the free six accepted literally any JSON. QTI keeps
     * "invalid response" apart from "wrong answer" for exactly this, and an
     * answer to a different question is the plainest invalid response there
     * is.
     *
     * It is structural and nothing more. Whether the identifiers name choices
     * the item offers is the interaction's business, because only the
     * interaction has the definition; whether the value is right is nobody's
     * business before submission.
     *
     * It never refuses an absent answer, and it accepts both spellings the
     * codec accepts: the Appendix C envelope, and the bare shapes the free
     * six keep at schema_version 1.
     */
    public function accepts(mixed $wire): bool
    {
        if ($wire === null || $wire === [] || $wire === '') {
            return true;
        }

        if (ResponseCodec::isEnvelope($wire)) {
            return $this->acceptsEnvelope($wire);
        }

        return match ($this->cardinality) {
            // A record arrives as a map of field name to value. A list is not
            // one, whatever it holds.
            Cardinality::Record => \is_array($wire) && !array_is_list($wire),
            Cardinality::Multiple, Cardinality::Ordered => \is_array($wire) && array_is_list($wire),
            Cardinality::Single => $this->acceptsSingle($wire),
        };
    }

    /**
     * @param array<array-key, mixed> $wire
     */
    private function acceptsEnvelope(array $wire): bool
    {
        $tag = $this->cardinality === Cardinality::Record
            ? 'record'
            : ($this->cardinality->isContainer() ? 'list' : 'base');

        if (!\array_key_exists($tag, $wire)) {
            return false;
        }

        $body = $wire[$tag];

        // { "base": null } is the envelope's own spelling of no answer.
        if ($body === null || $body === []) {
            return true;
        }

        if ($tag === 'record') {
            return \is_array($body);
        }

        // The envelope names its own base type, so a list of directed pairs
        // announces that it is not a list of identifiers. A record's fields
        // each name their own and are the field's business, not the shape's.
        return \is_array($body) && \array_key_exists($this->baseType->value, $body);
    }

    /**
     * One value of this base type, in the bare spelling a client posts.
     *
     * Deliberately generous within a base type and strict across them: a
     * numeric answer typed as "3.5" is a string on the wire and has to stay
     * acceptable, so the numeric types take both. What it will not do is read
     * a container or a map as a single value.
     */
    private function acceptsSingle(mixed $wire): bool
    {
        return match ($this->baseType) {
            BaseType::Boolean => \is_bool($wire) || $wire === 0 || $wire === 1,
            BaseType::Integer, BaseType::Float => \is_int($wire) || \is_float($wire) || (\is_string($wire) && is_numeric($wire)),
            // A file is a record of its own on the wire, or the bare storage
            // reference a client that had only that may post.
            BaseType::File => \is_string($wire) || (\is_array($wire) && !array_is_list($wire)),
            // A point and a pair are QTI strings ("0.5 0.4", "a b"), and a
            // client that posts the two members as a list is writing the same
            // value.
            BaseType::Point, BaseType::Pair, BaseType::DirectedPair => \is_string($wire)
                || (\is_array($wire) && array_is_list($wire) && \count($wire) === 2),
            default => \is_string($wire),
        };
    }

    public function matches(self $other): bool
    {
        return $this->key() === $other->key();
    }

    /**
     * Shape compatibility for a policy's appliesToShapes() list: cardinality
     * and base type must agree, and two records must agree about their field
     * signature unless one of them is the explicit wildcard.
     *
     * Treating every record as compatible with every other record recreated
     * exactly the wildcard the signature exists to prevent: without the
     * comparison, `record` cannot tell numeric's {stringValue, floatValue,
     * unit, nsf, ndp} from an extended response's {text, files}, so
     * numeric_precision becomes offerable on an essay and per_target becomes
     * offerable on a recording. Deferring the check to each policy's
     * validateParams means every future record-shaped policy reimplements it,
     * and the one that forgets is offerable on the wrong type with no test
     * able to catch it generically.
     *
     * anyRecord() (the empty field list) stays the wildcard a decorator
     * declares deliberately, so certainty_weighted and the rubric policies
     * keep applying everywhere.
     */
    public function isCompatibleWith(self $other): bool
    {
        if ($this->cardinality !== $other->cardinality) {
            return false;
        }

        if ($this->cardinality === Cardinality::Record) {
            return $this->fields === [] || $other->fields === [] || $this->signature() === $other->signature();
        }

        return $this->baseType === $other->baseType;
    }

    /**
     * The field signature alone, order insensitive, so two records declaring
     * the same fields in a different order are the same shape.
     */
    public function signature(): string
    {
        $signatures = array_map(
            static fn (ResponseField $field): string => $field->signature(),
            $this->fields,
        );
        sort($signatures);

        return implode(',', $signatures);
    }

    public function fieldNamed(string $name): ?ResponseField
    {
        foreach ($this->fields as $field) {
            if ($field->name === $name) {
                return $field;
            }
        }

        return null;
    }
}
