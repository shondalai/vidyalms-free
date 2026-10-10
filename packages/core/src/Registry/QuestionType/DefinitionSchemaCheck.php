<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * The one enforcer of a definition against the JSON Schema its own type
 * ships, so definitionSchema() is load-bearing rather than decorative.
 *
 * The design states it plainly: validateDefinition delegates to the schema
 * its own class ships. It did not. Every one of the fifteen Pro types
 * published `additionalProperties: false` and every one accepted an unknown
 * top-level key, and mutating one field per schema-declared enum or type
 * constraint produced thirty-four cases where the published schema rejected
 * a definition and the hand-written validator accepted it. Each of those
 * lands as a stored definition the type then reads through a
 * `=== 'x' ? ... : default` branch, so the item behaves differently from
 * what the author wrote with no error anywhere, and the generated catalogue, which is
 * the published contract an importer or a third-party authoring tool
 * validates against, describes an engine that does something else.
 *
 * With this in front of every validator, type sixteen costs a schema plus
 * the cross-field rules a schema cannot express, which is the promise ADR
 * 0011 was decided on.
 *
 * **The subset of JSON Schema below is the subset the definition schemas in
 * this repository actually use**, which is deliberate: a general validator
 * would be a dependency under `src/`, which the core forbids, and a
 * dependency that answers "valid" for a keyword it silently ignores is worse
 * than one that names the keyword it cannot check. An unknown keyword is
 * reported rather than skipped, so a schema growing a constraint this cannot
 * evaluate fails the build instead of being quietly unenforced.
 */
final class DefinitionSchemaCheck
{
    /** Everything a definition schema in this repository is built from. */
    private const UNDERSTOOD = [
        '$schema', '$id', 'title', 'description', 'type', 'enum', 'const', 'default',
        'properties', 'additionalProperties', 'required', 'items',
        'minItems', 'maxItems', 'minLength', 'maxLength', 'pattern',
        'minProperties', 'maxProperties',
        'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum',
    ];

    private function __construct()
    {
    }

    /**
     * The first line of a type's validateDefinition: refuse anything its own
     * published schema refuses, before the cross-field rules a schema cannot
     * express run.
     *
     * The field name in the exception is the dotted path of the offending
     * node, so a 422 points the author at the field rather than at the
     * document.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $definition
     *
     * @throws ValidationException
     */
    public static function enforce(array $schema, array $definition): void
    {
        $errors = self::errors($schema, $definition, '', true);

        if ($errors === []) {
            return;
        }

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        throw ValidationException::forField(self::fieldOf($errors[0]), $errors[0]);
    }

    /**
     * The same, for a provider that publishes a schema through
     * DefinitionContractInterface. A provider that publishes none is left
     * alone: the interface is optional and its documented default is that
     * the type is unvalidated.
     *
     * @param array<string, mixed> $definition
     *
     * @throws ValidationException
     */
    public static function enforceFor(QuestionTypeInterface $provider, array $definition): void
    {
        if (!$provider instanceof DefinitionContractInterface) {
            return;
        }

        self::enforce($provider->definitionSchema(), $definition);
    }

    /**
     * The dotted path an error message opens with, for the 422 field name.
     */
    private static function fieldOf(string $error): string
    {
        $at = strpos($error, ':');
        $path = $at === false ? '' : substr($error, 0, $at);

        return $path === '' || $path === 'the definition' ? 'definition' : $path;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string> one message per violation, empty when the
     *                      skeleton is a valid partial document
     */
    public static function errors(
        array $schema,
        mixed $value,
        string $path = '',
        bool $complete = false,
    ): array {
        $errors = [];

        foreach (array_keys($schema) as $keyword) {
            if (!\in_array($keyword, self::UNDERSTOOD, true)) {
                $errors[] = sprintf('%s: the check does not understand the "%s" keyword', self::at($path), $keyword);
            }
        }

        $errors = array_merge($errors, self::typeErrors($schema, $value, $path));

        if (\array_key_exists('const', $schema) && $value !== $schema['const']) {
            $errors[] = sprintf('%s: must be %s', self::at($path), self::show($schema['const']));
        }

        if (isset($schema['enum']) && \is_array($schema['enum']) && !\in_array($value, $schema['enum'], true)) {
            $errors[] = sprintf(
                '%s: %s is not one of %s',
                self::at($path),
                self::show($value),
                implode(', ', array_map(self::show(...), $schema['enum'])),
            );
        }

        if (\is_array($value) && array_is_list($value)) {
            $errors = array_merge($errors, self::listErrors($schema, $value, $path, $complete));
        } elseif (\is_array($value)) {
            $errors = array_merge($errors, self::objectErrors($schema, $value, $path, $complete));
        } elseif ($value instanceof \stdClass) {
            $errors = array_merge($errors, self::objectErrors($schema, get_object_vars($value), $path, $complete));
        } elseif (\is_string($value)) {
            $errors = array_merge($errors, self::stringErrors($schema, $value, $path));
        } elseif (\is_int($value) || \is_float($value)) {
            $errors = array_merge($errors, self::numberErrors($schema, $value, $path));
        }

        return array_values($errors);
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function typeErrors(array $schema, mixed $value, string $path): array
    {
        $declared = $schema['type'] ?? null;

        if ($declared === null) {
            return [];
        }

        $allowed = \is_array($declared) ? $declared : [$declared];

        foreach ($allowed as $type) {
            if (\is_string($type) && self::isOfType($value, $type)) {
                return [];
            }
        }

        return [sprintf(
            '%s: %s is not %s',
            self::at($path),
            self::show($value),
            implode(' or ', array_map(static fn (mixed $type): string => (string) $type, $allowed)),
        )];
    }

    private static function isOfType(mixed $value, string $type): bool
    {
        return match ($type) {
            // A JSON object is a PHP associative array almost everywhere,
            // because that is what json_decode with assoc gives back. It is a
            // \stdClass in the one place the difference is load bearing: an
            // empty PHP array encodes as [] and there is no other way to
            // write {} in PHP, so a document that has to round-trip through
            // JSON as an object with no keys carries one. Refusing it here
            // would mean the only spelling that survives json_encode is the
            // one the schema calls invalid.
            'object' => $value instanceof \stdClass
                || (\is_array($value) && ($value === [] || !array_is_list($value))),
            'array' => \is_array($value) && ($value === [] || array_is_list($value)),
            'string' => \is_string($value),
            'integer' => \is_int($value),
            'number' => \is_int($value) || \is_float($value),
            'boolean' => \is_bool($value),
            'null' => $value === null,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<mixed>          $value
     *
     * @return list<string>
     */
    private static function listErrors(array $schema, array $value, string $path, bool $complete): array
    {
        $errors = [];
        $minItems = $schema['minItems'] ?? null;
        $maxItems = $schema['maxItems'] ?? null;

        if (\is_int($minItems) && \count($value) < $minItems) {
            $errors[] = sprintf('%s: needs at least %d entries', self::at($path), $minItems);
        }

        if (\is_int($maxItems) && \count($value) > $maxItems) {
            $errors[] = sprintf('%s: takes at most %d entries', self::at($path), $maxItems);
        }

        $items = self::subSchema($schema['items'] ?? null);

        if ($items !== null) {
            foreach ($value as $index => $entry) {
                $errors = array_merge($errors, self::errors($items, $entry, $path . '[' . $index . ']', $complete));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<mixed, mixed>  $value
     *
     * @return list<string>
     */
    private static function objectErrors(array $schema, array $value, string $path, bool $complete): array
    {
        $errors = [];
        $properties = $schema['properties'] ?? null;
        $properties = \is_array($properties) ? $properties : [];
        $open = ($schema['additionalProperties'] ?? true) !== false;
        $minProperties = $schema['minProperties'] ?? null;
        $maxProperties = $schema['maxProperties'] ?? null;

        if (\is_int($minProperties) && \count($value) < $minProperties) {
            $errors[] = sprintf('%s: needs at least %d keys', self::at($path), $minProperties);
        }

        if (\is_int($maxProperties) && \count($value) > $maxProperties) {
            $errors[] = sprintf('%s: takes at most %d keys', self::at($path), $maxProperties);
        }

        foreach ($value as $key => $entry) {
            $child = $path === '' ? (string) $key : $path . '.' . $key;
            $property = self::subSchema($properties[$key] ?? null);

            if ($property !== null) {
                $errors = array_merge($errors, self::errors($property, $entry, $child, $complete));

                continue;
            }

            if (!$open) {
                $errors[] = sprintf('%s: the schema has no such key', self::at($child));
            }
        }

        // Required is enforced only against a complete document. A preset
        // skeleton is a partial definition by construction, and the prompt,
        // the choices and the answer key are the author's to write, so
        // enforcing it there would mean shipping a fake prompt and a fake
        // answer key inside every preset. That is exactly the invented
        // correctness the likert preset failed on.
        if ($complete) {
            foreach (\is_array($schema['required'] ?? null) ? $schema['required'] : [] as $key) {
                if (!\is_string($key) || \array_key_exists($key, $value)) {
                    continue;
                }

                $child = $path === '' ? $key : $path . '.' . $key;
                $errors[] = sprintf('%s: the schema requires this key', self::at($child));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function stringErrors(array $schema, string $value, string $path): array
    {
        $errors = [];
        $minLength = $schema['minLength'] ?? null;
        $maxLength = $schema['maxLength'] ?? null;

        if (\is_int($minLength) && mb_strlen($value) < $minLength) {
            $errors[] = sprintf('%s: needs at least %d characters', self::at($path), $minLength);
        }

        if (\is_int($maxLength) && mb_strlen($value) > $maxLength) {
            $errors[] = sprintf('%s: takes at most %d characters', self::at($path), $maxLength);
        }

        $pattern = $schema['pattern'] ?? null;

        // The schema's pattern is a JSON Schema (ECMA 262) regular
        // expression rather than a PCRE one, so it carries no delimiters and
        // no flags. It is delimited here and run with the u modifier, and it
        // is author-authored only in the sense that a schema ships with the
        // provider: a pattern that fails to compile is a provider bug and is
        // reported as one rather than silently passing everything.
        if (\is_string($pattern) && $pattern !== '') {
            $matched = @preg_match('/' . str_replace('/', '\/', $pattern) . '/u', $value);

            if ($matched === false) {
                $errors[] = sprintf('%s: the schema pattern "%s" does not compile', self::at($path), $pattern);
            } elseif ($matched === 0) {
                $errors[] = sprintf('%s: %s does not match the required form', self::at($path), self::show($value));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    private static function numberErrors(array $schema, int|float $value, string $path): array
    {
        $errors = [];
        $bounds = [
            ['minimum', static fn (float $bound): bool => $value < $bound, 'is below'],
            ['maximum', static fn (float $bound): bool => $value > $bound, 'is above'],
            ['exclusiveMinimum', static fn (float $bound): bool => $value <= $bound, 'is not above'],
            ['exclusiveMaximum', static fn (float $bound): bool => $value >= $bound, 'is not below'],
        ];

        foreach ($bounds as [$keyword, $breaches, $wording]) {
            $bound = $schema[$keyword] ?? null;

            if ((\is_int($bound) || \is_float($bound)) && $breaches((float) $bound)) {
                $errors[] = sprintf('%s: %s %s %s', self::at($path), self::show($value), $wording, self::show($bound));
            }
        }

        return $errors;
    }

    /**
     * A nested schema document, or null when the keyword carries none.
     *
     * @return array<string, mixed>|null
     */
    private static function subSchema(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }

        $schema = [];

        foreach ($value as $keyword => $constraint) {
            if (\is_string($keyword)) {
                $schema[$keyword] = $constraint;
            }
        }

        return $schema;
    }

    private static function at(string $path): string
    {
        return $path === '' ? 'the definition' : $path;
    }

    private static function show(mixed $value): string
    {
        return match (true) {
            \is_string($value) => '"' . $value . '"',
            \is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            \is_scalar($value) => (string) $value,
            \is_array($value) => array_is_list($value) ? 'a list' : 'an object',
            default => \gettype($value),
        };
    }
}
