<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Response;

/**
 * The one function that turns a wire response into a decoded value,
 * whichever of the two shapes it arrives in.
 *
 * Every response travels in the QTI Best Practices Appendix C JSON envelope:
 *
 *   { "base": { "identifier": "c2" } }
 *   { "list": { "directed_pair": [["s1","t3"], ["s2","t1"]] } }
 *   { "record": [ { "name": "g1", "base": { "identifier": "c3" } } ] }
 *   { "base": null }
 *
 * Values are self-describing, so validation is one function rather than one
 * per type, a third-party type is interoperable without a core change, and
 * the QTI export of a candidate response is a copy rather than a transform.
 *
 * The free six keep their current bare shapes at schema_version 1: a bare
 * string for single choice, string[] for multiple choice and ordering, a
 * boolean for true or false, a {blank: value} map for fill in the blank.
 * This codec accepts both and normalises inward, so no stored answer and no
 * shipped client breaks.
 */
final class ResponseCodec
{
    private function __construct()
    {
    }

    /**
     * Whether a wire value is written in the envelope rather than bare.
     */
    public static function isEnvelope(mixed $wire): bool
    {
        if (!\is_array($wire)) {
            return false;
        }

        foreach (['base', 'list', 'record'] as $tag) {
            if (\array_key_exists($tag, $wire)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The decoded value in the type's own terms: a string, a bool, a float,
     * a list of members, or a field map.
     */
    public static function decode(mixed $wire, ResponseShape $shape): ResponseValue
    {
        if ($wire === null) {
            return ResponseValue::null($shape);
        }

        $value = self::isEnvelope($wire) ? self::unwrap($wire) : $wire;

        if ($value === null) {
            return ResponseValue::null($shape);
        }

        return ResponseValue::of($shape, self::coerce($value, $shape));
    }

    /**
     * The envelope form of a decoded value, which is what an export writes.
     *
     * @return array<string, mixed>
     */
    public static function encode(ResponseValue $value, ResponseShape $shape): array
    {
        $base = $shape->baseType->value;

        if ($value->isNullOrEmpty()) {
            return ['base' => null];
        }

        if ($shape->cardinality === Cardinality::Record) {
            $record = [];

            foreach ($value->fields() as $name => $field) {
                $record[] = ['name' => $name, 'base' => [$base => $field]];
            }

            return ['record' => $record];
        }

        if ($shape->cardinality->isContainer()) {
            return ['list' => [$base => $value->members()]];
        }

        return ['base' => [$base => $value->value]];
    }

    private static function unwrap(mixed $wire): mixed
    {
        if (!\is_array($wire)) {
            return $wire;
        }

        if (\array_key_exists('base', $wire)) {
            $base = $wire['base'];

            if (!\is_array($base) || $base === []) {
                return null;
            }

            return reset($base);
        }

        if (\array_key_exists('list', $wire)) {
            $list = $wire['list'];

            if (!\is_array($list) || $list === []) {
                return [];
            }

            $members = reset($list);

            return \is_array($members) ? $members : [];
        }

        $record = $wire['record'] ?? null;

        if (!\is_array($record)) {
            return [];
        }

        $fields = [];

        foreach ($record as $entry) {
            if (!\is_array($entry) || !\is_string($entry['name'] ?? null)) {
                continue;
            }

            $base = $entry['base'] ?? null;
            $fields[$entry['name']] = \is_array($base) && $base !== [] ? reset($base) : null;
        }

        return $fields;
    }

    private static function coerce(mixed $value, ResponseShape $shape): mixed
    {
        if ($shape->cardinality === Cardinality::Record) {
            return \is_array($value) ? $value : [];
        }

        if ($shape->cardinality->isContainer()) {
            // A bare scalar where a container belongs is a malformed
            // response, not a one-member container. Promoting it would turn
            // a client bug into a partial mark, which is exactly the kind of
            // silent scoring change this design exists to prevent.
            return \is_array($value) ? array_values($value) : [];
        }

        if ($shape->baseType === BaseType::Boolean && (\is_int($value) && ($value === 0 || $value === 1))) {
            return $value === 1;
        }

        return $value;
    }
}
