<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\QuestionType;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Shared validation and presentation primitives for the free question types.
 * Presentation helpers return only learner-safe fields; nothing here may ever
 * copy correctness, accepted answers or feedback into a presented shape.
 */
final class DefinitionRules
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function requirePrompt(array $definition): void
    {
        $prompt = $definition['prompt'] ?? null;

        if (!\is_string($prompt) || trim($prompt) === '') {
            throw ValidationException::forField('prompt', 'A prompt is required.');
        }
    }

    /**
     * Validates a non-empty list of {"key": string, "label": string} entries
     * with unique keys and returns the keys in order.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    public static function requireKeyedList(array $definition, string $field, bool $withLabel): array
    {
        $entries = $definition[$field] ?? null;

        if (!\is_array($entries) || $entries === [] || !array_is_list($entries)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, sprintf('At least one entry is required in "%s".', $field));
        }

        $keys = [];

        foreach ($entries as $entry) {
            if (!\is_array($entry)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field, 'Each entry must be an object.');
            }

            $key = $entry['key'] ?? null;

            if (!\is_string($key) || trim($key) === '') {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field, 'Each entry needs a non-empty string key.');
            }

            if (\in_array($key, $keys, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field, sprintf('Entry key "%s" is duplicated.', $key));
            }

            if ($withLabel && !\is_string($entry['label'] ?? null)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field, 'Each entry needs a string label.');
            }

            $keys[] = $key;
        }

        return $keys;
    }

    /**
     * The learner-safe projection of a keyed list: keys and labels only.
     *
     * When a choice sequence is given, the entries come back in the order
     * the learner actually saw. The sequence is produced once per primitive
     * from the attempt seed and honours the per-choice fixed flag there, so
     * no type reimplements shuffling and none of them can get fixed wrong.
     *
     * @param array<string, mixed> $definition
     * @param list<string>         $order      the presented key order, empty for authored order
     *
     * @return list<array{key: string, label: string}>
     */
    public static function presentKeyedList(array $definition, string $field, array $order = []): array
    {
        $entries = $definition[$field] ?? null;

        if (!\is_array($entries)) {
            return [];
        }

        $presented = [];

        foreach ($entries as $entry) {
            if (!\is_array($entry) || !\is_string($entry['key'] ?? null)) {
                continue;
            }

            $label = $entry['label'] ?? '';
            $presented[$entry['key']] = [
                'key' => $entry['key'],
                'label' => \is_string($label) ? $label : '',
            ];
        }

        if ($order === []) {
            return array_values($presented);
        }

        $ordered = [];

        foreach ($order as $key) {
            if (isset($presented[$key])) {
                $ordered[] = $presented[$key];
                unset($presented[$key]);
            }
        }

        // Anything the sequence did not name keeps its authored place at the
        // end, so a stale sequence degrades rather than dropping options.
        return [...$ordered, ...array_values($presented)];
    }

    /**
     * The keys of a keyed list, in authored order. The universe a selection
     * policy counts distractors against.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    public static function keysOf(array $definition, string $field): array
    {
        $entries = $definition[$field] ?? null;

        if (!\is_array($entries)) {
            return [];
        }

        $keys = [];

        foreach ($entries as $entry) {
            if (\is_array($entry) && \is_string($entry['key'] ?? null)) {
                $keys[] = $entry['key'];
            }
        }

        return $keys;
    }

    /**
     * The constraint fields a learner is allowed to see: how many choices
     * are required and the authored messages that say so.
     *
     * These are presentable by construction. A constraint message may never
     * be derived from correctness, which is the rule that stops a future
     * type author leaking the key through a validation message.
     *
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public static function presentConstraints(array $definition): array
    {
        $presented = [];

        foreach (['min_selections', 'max_selections'] as $field) {
            if (\is_int($definition[$field] ?? null)) {
                $presented[$field] = $definition[$field];
            }
        }

        foreach (['min_message', 'max_message'] as $field) {
            if (\is_string($definition[$field] ?? null)) {
                $presented[$field] = $definition[$field];
            }
        }

        if (\is_array($definition['layout_classes'] ?? null)) {
            $presented['layout_classes'] = array_values(array_filter($definition['layout_classes'], '\is_string'));
        }

        return $presented;
    }

    /**
     * The JSON Schema envelope every free type's definitionSchema() uses:
     * an object with additionalProperties false, so a definition is
     * validated rather than being a free-form bag.
     *
     * @param array<string, array<string, mixed>> $properties
     * @param list<string>                        $required
     *
     * @return array<string, mixed>
     */
    public static function schema(string $owner, array $properties, array $required): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'title' => $owner,
            'type' => 'object',
            'additionalProperties' => false,
            'required' => $required,
            'properties' => $properties,
        ];
    }

    /**
     * The shared keyed-list fragment: an entry has a key, optionally a
     * label, and QTI's fixed attribute.
     *
     * @return array<string, mixed>
     */
    public static function keyedListSchema(bool $withLabel, string $valueField = 'label'): array
    {
        $properties = [
            'key' => ['type' => 'string', 'minLength' => 1],
            'fixed' => ['type' => 'boolean', 'default' => false],
        ];

        if ($withLabel) {
            $properties[$valueField] = ['type' => 'string'];
        }

        return [
            'type' => 'array',
            'minItems' => 1,
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => $withLabel ? ['key', $valueField] : ['key'],
                'properties' => $properties,
            ],
        ];
    }

    /**
     * media_ref/1. A private still image, named by the storage ref it is
     * stored under and never by a URL: the only URL the core emits for a
     * private file is a signed, expiring one, minted at read time, so a
     * definition carrying one would carry a signature that is dead the
     * moment it expires.
     *
     * It lives here, beside policy_ref/1, because five type schemas across
     * two provider families embed it and one $id must carry one body. It
     * did not: one type published a bare-string spelling under the same
     * $id, so a vendor copying media_ref/1 into their own schema, as the
     * design invites, had two mutually exclusive definitions to copy from
     * and the one they picked decided whether their media survived a round
     * trip. The generated catalogue's $id check is what keeps it one body.
     *
     * The type union carries null because a canvas may have no background
     * image at all; an embedding schema that requires the medium says so
     * with its own required list, which is where "this type needs an image"
     * belongs. The long description is an object rather than a bare path
     * because the read path replaces a ref with a sibling named url inside
     * the ref's own parent, so two refs directly under one node would both
     * land in one url and one of the two would be lost.
     *
     * @return array<string, mixed>
     */
    public static function mediaRefSchema(): array
    {
        return [
            '$id' => 'media_ref/1',
            'type' => ['object', 'null'],
            'additionalProperties' => false,
            'required' => ['storage_ref', 'intrinsic_width', 'intrinsic_height', 'alt'],
            'properties' => [
                'storage_ref' => ['type' => 'string', 'minLength' => 1],
                'intrinsic_width' => ['type' => 'integer', 'minimum' => 1],
                'intrinsic_height' => ['type' => 'integer', 'minimum' => 1],
                'alt' => ['type' => 'string', 'minLength' => 1],
                'long_description' => [
                    'type' => ['object', 'null'],
                    'additionalProperties' => false,
                    'required' => ['storage_ref'],
                    'properties' => ['storage_ref' => ['type' => 'string', 'minLength' => 1]],
                ],
            ],
        ];
    }
    /**
     * policy_ref/1: the shared sub-schema a definition names its marking
     * rule through. Additive-only for the life of version 1, because it is
     * embedded inline in every type schema and a breaking change would need
     * a coordinated upgrader in every embedding type, including a
     * third-party one whose vendor never learns core changed a fragment it
     * copied.
     *
     * @return array<string, mixed>
     */
    public static function policyRefSchema(): array
    {
        return [
            '$id' => 'policy_ref/1',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['key'],
            'properties' => [
                'key' => ['type' => 'string', 'minLength' => 1],
                'params' => ['type' => 'object'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function requireOptionalStringField(array $definition, string $field): void
    {
        if (\array_key_exists($field, $definition) && !\is_string($definition[$field])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, sprintf('"%s" must be a string when given.', $field));
        }
    }

    /**
     * Validates a list of strings under $field and returns it.
     *
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    public static function requireStringList(array $definition, string $field, bool $allowEmpty): array
    {
        $values = $definition[$field] ?? null;

        if (!\is_array($values) || !array_is_list($values)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, sprintf('"%s" must be a list of strings.', $field));
        }

        if (!$allowEmpty && $values === []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, sprintf('"%s" must not be empty.', $field));
        }

        foreach ($values as $value) {
            if (!\is_string($value)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField($field, sprintf('"%s" must contain only strings.', $field));
            }
        }

        /** @var list<string> $values */
        return $values;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function requireOptionalBoolField(array $definition, string $field): void
    {
        if (\array_key_exists($field, $definition) && !\is_bool($definition[$field])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, sprintf('"%s" must be a boolean when given.', $field));
        }
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function feedback(array $definition): ?string
    {
        $feedback = $definition['feedback'] ?? null;

        return \is_string($feedback) ? $feedback : null;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public static function caseSensitive(array $definition): bool
    {
        return ($definition['case_sensitive'] ?? false) === true;
    }

    /**
     * Trims, and lowercases unless the comparison is case sensitive.
     */
    public static function normalise(string $text, bool $caseSensitive): string
    {
        $text = trim($text);

        return $caseSensitive ? $text : strtolower($text);
    }

    /**
     * @param list<string> $accepted
     */
    public static function matchesAccepted(mixed $answer, array $accepted, bool $caseSensitive): bool
    {
        if (!\is_string($answer)) {
            return false;
        }

        $normalised = self::normalise($answer, $caseSensitive);

        foreach ($accepted as $candidate) {
            if (self::normalise($candidate, $caseSensitive) === $normalised) {
                return true;
            }
        }

        return false;
    }
}
