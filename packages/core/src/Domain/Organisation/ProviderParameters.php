<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Organisation;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * The stored parameter bag of an organisation provider, checked against the
 * schema that provider publishes.
 *
 * Two authoring surfaces write one of these: a catalogue entry's `params` and a
 * compliance requirement's `rule_params`. Both store an object nothing but the
 * provider interprets, and both had the same hole before this existed, which is
 * that a key the provider never declared could be stored and then silently
 * ignored for ever. An author who mistypes `include_descendents` deserves a 422
 * naming the field, not a subtree entry that quietly reaches one unit.
 *
 * It is deliberately not a general JSON Schema implementation. It reads the
 * subset every shipped organisation schema uses: a closed object, a required
 * list, and per-property `type`, `enum`, `minimum`, `maximum` and `maxLength`.
 * A keyword it does not know admits the value, which is the same direction
 * {@see \Shondalai\VidyaLMS\Core\Domain\Reporting\ReportParameters} takes: this
 * is a check on the author's typing, and the provider is still responsible for
 * its own meaning.
 *
 * It is a sibling of that class rather than a call into it. The report
 * validator lets three framework paging keys through any closed schema, which
 * is correct for a report route and wrong for a stored bag: `page` written into
 * a catalogue entry's parameters would be a key nothing reads, kept for ever.
 */
final class ProviderParameters
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $schema the provider's own paramsSchema()
     * @param array<string, mixed> $params the bag as it arrived
     *
     * @return array<string, mixed> the same bag, typed by the schema
     *
     * @throws ValidationException naming the first parameter that is wrong
     */
    public static function validate(array $schema, array $params, string $field = 'params'): array
    {
        /** @var array<string, mixed> $properties */
        $properties = \is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $open = ($schema['additionalProperties'] ?? true) !== false;

        foreach (\is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
            if (\is_string($name) && !\array_key_exists($name, $params)) {
                throw ValidationException::forField(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $field . '.' . $name,
                    'This source needs this parameter.',
                );
            }
        }

        $typed = [];

        foreach ($params as $name => $value) {
            $rule = $properties[$name] ?? null;

            if (!\is_array($rule)) {
                if (!$open) {
                    throw ValidationException::forField(
                        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                        $field . '.' . $name,
                        'This source has no such parameter.',
                    );
                }

                $typed[$name] = $value;

                continue;
            }

            $typed[$name] = self::check($field . '.' . $name, $rule, $value);
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
            throw ValidationException::forField($name, 'That is not one of the values this source takes.');
        }

        if (\is_int($value) || \is_float($value)) {
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
            throw ValidationException::forField($name, sprintf('Use at most %d characters here.', $rule['maxLength']));
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
                // A type word this validator does not know admits the value,
                // because refusing what it cannot read would make an unknown
                // keyword a gate, and this is a typing check rather than one.
                default => true,
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
