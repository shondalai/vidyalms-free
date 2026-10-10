<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Reporting;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * The incoming filter bag, checked against the definition's own published
 * schema and typed by it.
 *
 * `ReportDefinitionInterface::parameterSchema()` states three purposes: a
 * manage screen renders the filter form from it, a schedule validates a saved
 * parameter set at save time rather than at 03:00, and the export layer takes
 * column types from it. It had no caller at all, so none of the three
 * happened and parameters reached `ReportContext` exactly as the query string
 * spelled them.
 *
 * That is how the interface's own **"a condition is not a filter"** rule was
 * bypassed. A condition is fixed by the definition and invisible to the
 * viewer; a filter is chosen at run time and has to be declared. With nothing
 * checking the bag against the declaration, a caller could supply any
 * parameter name at all and a provider that happened to read it would honour
 * it, which is the group-leader leak wearing a different hat.
 *
 * **It is a deliberate subset of JSON Schema, and the subset is the part the
 * definitions use**: `type` (including a list of types), `enum`, `minimum`,
 * `maximum`, `maxLength`, `required`, and `additionalProperties: false`. A
 * keyword this does not understand is ignored rather than guessed at, because
 * a validator that half-implements a composition keyword is a validator that
 * silently admits what it meant to refuse. A definition needing more than this
 * validates it in its own `describe()`, which is where it already refuses what
 * it cannot serve.
 *
 * **It types as well as checks**, and that is not a convenience. A query
 * string is always strings, so `course_id=7` arrives as `"7"` and a schema
 * saying `integer` would refuse every real request unless the check also
 * produced the integer. The coercion is exact: a whole number for an integer,
 * a number for a number, the two spellings of a boolean, and nothing else.
 */
final class ReportParameters
{
    /**
     * The keys the framework itself puts in the bag, which a report never
     * declares and is never refused for.
     *
     * The two paging keys are written into every request by the route rather
     * than by the caller, so a definition that closed its schema with
     * `additionalProperties: false` was unreachable through its own route: the
     * handler added `page`, this refused it, and the report answered 422 for a
     * request nobody had made. A report that publishes a closed schema is
     * publishing a statement about its filters, not about the framework's own
     * paging, and no schema should have to declare somebody else's parameters
     * to be readable.
     *
     * @var list<string>
     */
    public const RESERVED = ['page', 'per_page', 'cursor'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $schema     the definition's parameterSchema()
     * @param array<string, mixed> $parameters the bag as it arrived
     *
     * @return array<string, mixed> the same bag, typed by the schema
     *
     * @throws ValidationException naming the first parameter that is wrong
     */
    public static function validate(array $schema, array $parameters): array
    {
        /** @var array<string, mixed> $properties */
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $open = ($schema['additionalProperties'] ?? true) !== false;

        foreach (\is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
            if (\is_string($name) && !\array_key_exists($name, $parameters)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($name, 'This report needs this filter.');
            }
        }

        $typed = [];

        foreach ($parameters as $name => $value) {
            $rule = $properties[$name] ?? null;

            if (!\is_array($rule)) {
                if (!$open && !\in_array((string) $name, self::RESERVED, true)) {
                    // The whole point of the rule: a parameter the definition
                    // never declared is not a filter this report offers.
                    throw ValidationException::forField(
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        (string) $name,
                        'This report has no such filter.',
                    );
                }

                $typed[$name] = $value;

                continue;
            }

            $typed[$name] = self::check((string) $name, $rule, $value);
        }

        return $typed;
    }

    /**
     * @param array<string, mixed> $rule
     */
    private static function check(string $name, array $rule, mixed $value): mixed
    {
        $declared = $rule['type'] ?? null;
        /** @var list<mixed> $types */
        $types = \is_array($declared) ? array_values($declared) : ($declared === null ? [] : [$declared]);
        $value = self::coerce($types, $value);

        if ($types !== [] && !self::matchesAny($types, $value)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($name, sprintf(
                'Expected %s here.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(' or ', array_map(static fn (mixed $type): string => (string) $type, $types)),
            ));
        }

        if (\is_array($rule['enum'] ?? null) && !\in_array($value, $rule['enum'], true)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($name, sprintf(
                'Choose one of %s.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', array_map(
                    static fn (mixed $option): string => \is_scalar($option) ? (string) $option : '?',
                    $rule['enum'],
                )),
            ));
        }

        if ((\is_int($value) || \is_float($value))) {
            if (\is_numeric($rule['minimum'] ?? null) && $value < $rule['minimum']) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($name, sprintf('The smallest value here is %s.', $rule['minimum']));
            }

            if (\is_numeric($rule['maximum'] ?? null) && $value > $rule['maximum']) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($name, sprintf('The largest value here is %s.', $rule['maximum']));
            }
        }

        if (\is_string($value) && \is_int($rule['maxLength'] ?? null) && mb_strlen($value) > $rule['maxLength']) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($name, sprintf(
                'Use at most %d characters here.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $rule['maxLength'],
            ));
        }

        return $value;
    }

    /**
     * A query string carries strings, so an `integer` filter arrives spelled
     * as one. The coercion is exact and refuses everything else by leaving the
     * value alone for the type check to reject.
     *
     * @param list<mixed> $types
     */
    private static function coerce(array $types, mixed $value): mixed
    {
        if (!\is_string($value)) {
            return $value;
        }

        if (\in_array('integer', $types, true) && preg_match('/^-?[0-9]{1,18}$/D', $value) === 1) {
            return (int) $value;
        }

        if (\in_array('number', $types, true) && is_numeric($value)) {
            return $value + 0;
        }

        if (\in_array('boolean', $types, true) && \in_array($value, ['true', 'false', '1', '0'], true)) {
            return $value === 'true' || $value === '1';
        }

        return $value;
    }

    /**
     * @param list<mixed> $types
     */
    private static function matchesAny(array $types, mixed $value): bool
    {
        foreach ($types as $type) {
            $matches = match ($type) {
                'string' => \is_string($value),
                'integer' => \is_int($value),
                'number' => \is_int($value) || \is_float($value),
                'boolean' => \is_bool($value),
                'array' => \is_array($value),
                'object' => \is_array($value),
                'null' => $value === null,
                // A type word this validator does not know admits the value
                // rather than refusing it, for the reason the class docblock
                // gives: half-understanding a keyword is how a validator
                // silently refuses what somebody meant to allow.
                default => true,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
