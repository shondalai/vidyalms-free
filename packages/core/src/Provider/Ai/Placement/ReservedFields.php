<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Ai\Placement;

/**
 * The field names no AI output may ever bind to, published once so both the
 * placement rule and the task rule read the same list.
 *
 * **This is where the no-mark redline stops being a screen spec and becomes a
 * contract.** The generic mount refuses to bind a task output to any field
 * outside a placement's fillableFields(), and a registration-time check
 * additionally refuses a task whose declared output schema binds a property
 * to one of these. With the rule written only into a screen spec, a vendor
 * task declaring `{suggested_mark: number}` plus a vendor field-fill
 * placement passed every structural test the design had, because a
 * field-filling helper declares no TargetsExtensionPoint and the tests looked
 * only at extension points.
 *
 * Two families, and the reasoning differs.
 *
 * **Scored.** A score that plays a determining role in a downstream decision
 * is automated decision-making even with a human button in front of it, and
 * prefilling a mark would additionally put the product inside the high-risk
 * category for evaluating learning outcomes. So no placement anywhere in the
 * product lists a score field, and the absence is asserted rather than
 * described.
 *
 * **Gated.** Availability, credential and enrolment fields decide who may
 * reach what. A helper that could propose one would be authoring an access
 * rule, and the catalogue names that as a refusal rather than an omission.
 */
final class ReservedFields
{
    /**
     * Field names, and the substrings a field name may not contain, that
     * carry or determine a mark.
     *
     * Matched on whole words split out of the name rather than as
     * substrings, so `feedback_to_learner` is allowed while `learner_mark`
     * is not, and a field called `remarks` is not refused for containing
     * `mark`.
     */
    public const SCORED = [
        'score',
        'scores',
        'mark',
        'marks',
        'grade',
        'grades',
        'points',
        'percentage',
        'percent',
        'awarded',
        'pass',
        'passed',
        'passing',
        'weight',
        'weighting',
    ];

    /**
     * The three names that are deliberately **not** reserved, recorded here
     * because leaving them out looks like an oversight and is not.
     *
     * `correct`, `key` and `answer` are what a question definition is made
     * of: the free single-choice type declares an option list of `(key,
     * label)` pairs and a `correct` key, so reserving any of them would
     * refuse every question-drafting task in the product, including the free
     * deterministic one. The redline is about a **mark**, which is a
     * judgement about a person, and not about an item's own answer, which is
     * a property of the item.
     */
    public const DELIBERATELY_ALLOWED = ['correct', 'key', 'answer'];

    /** Field names that decide who may reach what, or what a person has earned. */
    public const GATED = [
        'availability',
        'available',
        'unlock',
        'unlocked',
        'prerequisite',
        'prerequisites',
        'credential',
        'certificate',
        'enrolment',
        'enrolled',
        'entitlement',
        'expiry',
        'expires',
        'seat',
        'seats',
    ];

    private function __construct()
    {
    }

    /**
     * Whether one field name is reserved.
     *
     * The name is split at every case change and at every character that is
     * not a letter or a digit, and each piece is compared whole. Splitting
     * rather than substring matching, in both directions: a substring scan
     * would refuse `remarks` for containing `mark` and would miss
     * `suggestedMark`.
     */
    public static function isReserved(string $field): bool
    {
        foreach (self::segments($field) as $segment) {
            if (\in_array($segment, self::SCORED, true) || \in_array($segment, self::GATED, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every reserved name inside a declared output schema, walked to any
     * depth, because a property nested under an array of objects binds a
     * field exactly as a top-level one does.
     *
     * @param array<string, mixed> $schema
     *
     * @return list<string>
     */
    public static function boundBy(array $schema, string $path = ''): array
    {
        $found = [];

        $properties = $schema['properties'] ?? null;

        if (\is_array($properties)) {
            foreach ($properties as $name => $subSchema) {
                $name = (string) $name;
                $here = $path === '' ? $name : $path . '.' . $name;

                if (self::isReserved($name)) {
                    $found[] = $here;
                }

                if (!\is_array($subSchema)) {
                    continue;
                }

                /** @var array<string, mixed> $subSchema */
                $nestedSchema = $subSchema;

                foreach (self::boundBy($nestedSchema, $here) as $nested) {
                    $found[] = $nested;
                }
            }
        }

        $items = $schema['items'] ?? null;

        if (\is_array($items)) {
            /** @var array<string, mixed> $items */
            $itemSchema = $items;

            foreach (self::boundBy($itemSchema, $path === '' ? 'items' : $path . '.items') as $nested) {
                $found[] = $nested;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $text): array
    {
        $spaced = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $text) ?? $text;
        $pieces = preg_split('/[^A-Za-z0-9]+/', $spaced) ?: [];
        $segments = [];

        foreach ($pieces as $piece) {
            if ($piece !== '') {
                $segments[] = strtolower($piece);
            }
        }

        return $segments;
    }
}
