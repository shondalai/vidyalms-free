<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

/**
 * The one class that sits between a question type's published
 * `definitionSchema()` and a bridge's `supportedSchemaKeywords()`.
 *
 * **What it refuses is a strengthening, and that correction matters.** An
 * earlier statement of the rule said it asserts the narrowing is a subset and
 * refuses a **widening**, which resolves to "refuse every route" for every
 * shipped question type: dropping a constraint keyword is a widening by
 * definition, since a schema without `minItems` accepts everything the schema
 * with `minItems` accepts and more. The free multiple choice type alone uses
 * `minLength`, `minItems`, `minimum`, `enum` and `additionalProperties:
 * false`, and no provider strict mode supports that whole set, so the
 * flagship task would never have run.
 *
 * So the narrowed schema is **decoding guidance and not the gate**. It MAY be
 * weaker than the type's own schema and MUST NOT be stronger. The narrower
 * drops what the route cannot enforce, records every drop, and refuses only a
 * transformation that could reject a document the type's own schema accepts.
 * DefinitionSchemaCheck stays the authoritative validator either way, which is
 * why the narrowing has to be explicit and why giving the rule a class gives
 * it a test.
 *
 * An unknown keyword is refused rather than dropped. DefinitionSchemaCheck
 * errors on a keyword it does not understand rather than skipping it, so a
 * schema growing a constraint this class cannot reason about must fail here
 * too instead of being quietly unenforced on the wire.
 */
final class AiSchemaNarrower
{
    /**
     * Everything a definition schema in this repository is built from. It
     * mirrors DefinitionSchemaCheck::UNDERSTOOD deliberately rather than
     * reading it, because that constant is private and the mirror is asserted
     * by a test: a keyword added there and not here is a keyword this class
     * would refuse, which is the safe direction.
     */
    public const UNDERSTOOD = [
        '$schema', '$id', 'title', 'description', 'type', 'enum', 'const', 'default',
        'properties', 'additionalProperties', 'required', 'items',
        'minItems', 'maxItems', 'minLength', 'maxLength', 'pattern',
        'minProperties', 'maxProperties',
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum',
    ];

    /**
     * Keywords that carry structure rather than constrain a value. They are
     * never dropped: dropping `properties` would not weaken the schema, it
     * would delete it, and a caller holding an empty object schema has no
     * guidance to send at all.
     */
    public const STRUCTURAL = ['$schema', '$id', 'title', 'description', 'default', 'properties', 'items'];

    /**
     * Narrow one schema to the keywords a route enforces.
     *
     * @param array<string, mixed> $schema             the type's own published schema
     * @param list<string>         $supportedKeywords  what the bridge declared it enforces
     *
     * @throws SchemaStrengthenedException on a keyword nothing here understands
     */
    public function narrow(array $schema, array $supportedKeywords): NarrowedSchema
    {
        $dropped = [];
        $narrowed = $this->reduce($schema, $supportedKeywords, '', $dropped);

        // The self check. Narrowing only ever drops, so this can only fail on
        // a bug in this class, which is exactly the failure a caller must
        // never be handed a schema from.
        $this->assertNotStronger($schema, $narrowed);

        return NarrowedSchema::of($narrowed, $dropped);
    }

    /**
     * The answer for a bridge that declares no constrained decoding: nothing
     * goes on the wire as guidance, and every constraint the schema carried
     * is named as unenforced so the review screen can say which.
     *
     * @param array<string, mixed> $schema
     */
    public function unenforced(array $schema): NarrowedSchema
    {
        $dropped = [];
        $this->reduce($schema, [], '', $dropped);

        return NarrowedSchema::unenforced($dropped);
    }

    /**
     * Whether a candidate schema could reject a document the original
     * accepts.
     *
     * The rule is decidable rather than clever, which is what makes it worth
     * having: a constraint keyword in the candidate must appear in the
     * original with an identical value, and a structural node in the
     * candidate must have a counterpart in the original. Anything else is a
     * strengthening, whether it tightened a bound, added a required field,
     * closed an open object or invented a property. Everything the candidate
     * left out is a weakening and is always allowed.
     *
     * @param array<string, mixed> $original
     * @param array<string, mixed> $candidate
     *
     * @throws SchemaStrengthenedException
     */
    public function assertNotStronger(array $original, array $candidate, string $path = ''): void
    {
        foreach ($candidate as $keyword => $value) {
            $keyword = (string) $keyword;

            if (!\in_array($keyword, self::UNDERSTOOD, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw SchemaStrengthenedException::unknownKeyword($path, $keyword);
            }

            if (!array_key_exists($keyword, $original)) {
                throw SchemaStrengthenedException::at(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $path,
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    sprintf('it declares %s, which the original does not', $keyword),
                );
            }

            $originalValue = $original[$keyword];

            if ($keyword === 'properties' && \is_array($value) && \is_array($originalValue)) {
                foreach ($value as $property => $subSchema) {
                    $property = (string) $property;

                    if (!\is_array($subSchema)) {
                        throw SchemaStrengthenedException::at(
                            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                            self::join($path, 'properties.' . $property),
                            'a property schema must be an object',
                        );
                    }

                    if (!isset($originalValue[$property]) || !\is_array($originalValue[$property])) {
                        throw SchemaStrengthenedException::at(
                            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                            self::join($path, 'properties.' . $property),
                            'the original declares no such property',
                        );
                    }

                    /** @var array<string, mixed> $subSchema */
                    /** @var array<string, mixed> $originalSub */
                    $originalSub = $originalValue[$property];
                    $this->assertNotStronger($originalSub, $subSchema, self::join($path, $property));
                }

                continue;
            }

            if ($keyword === 'items' && \is_array($value) && \is_array($originalValue)) {
                /** @var array<string, mixed> $value */
                /** @var array<string, mixed> $originalValue */
                $this->assertNotStronger($originalValue, $value, self::join($path, 'items'));

                continue;
            }

            if ($originalValue !== $value) {
                throw SchemaStrengthenedException::at(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $path,
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    sprintf('%s was changed rather than dropped', $keyword),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string>         $supportedKeywords
     * @param list<string>         $dropped
     *
     * @return array<string, mixed>
     */
    private function reduce(array $schema, array $supportedKeywords, string $path, array &$dropped): array
    {
        $result = [];

        foreach ($schema as $keyword => $value) {
            $keyword = (string) $keyword;

            if (!\in_array($keyword, self::UNDERSTOOD, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw SchemaStrengthenedException::unknownKeyword($path, $keyword);
            }

            if ($keyword === 'properties' && \is_array($value)) {
                $properties = [];

                foreach ($value as $property => $subSchema) {
                    if (!\is_array($subSchema)) {
                        continue;
                    }

                    /** @var array<string, mixed> $subSchema */
                    $properties[(string) $property] = $this->reduce(
                        $subSchema,
                        $supportedKeywords,
                        self::join($path, (string) $property),
                        $dropped,
                    );
                }

                $result['properties'] = $properties;

                continue;
            }

            if ($keyword === 'items' && \is_array($value)) {
                /** @var array<string, mixed> $value */
                $result['items'] = $this->reduce($value, $supportedKeywords, self::join($path, 'items'), $dropped);

                continue;
            }

            if (\in_array($keyword, self::STRUCTURAL, true) || \in_array($keyword, $supportedKeywords, true)) {
                $result[$keyword] = $value;

                continue;
            }

            $dropped[] = self::join($path, $keyword);
        }

        return $result;
    }

    private static function join(string $path, string $segment): string
    {
        return $path === '' ? $segment : $path . '.' . $segment;
    }
}
