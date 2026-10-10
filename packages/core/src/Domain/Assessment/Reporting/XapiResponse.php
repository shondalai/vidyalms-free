<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting;

/**
 * The learner's stored answer written in the xAPI response format.
 *
 * The format is shaped by cardinality rather than by type: a list of
 * identifiers joins on the item separator, a pair joins on the pair
 * separator, and a scalar is itself. That is why this is one function
 * rather than one per type: reading the shape keeps the emitter generic,
 * and a switch on type id here is the fork-the-core failure the design
 * names.
 *
 * Nothing here reads a definition, so no correct answer can reach a
 * statement through this path. What a correct response looks like is the
 * owning type's business and is generated separately, server side, at
 * emission time.
 */
final class XapiResponse
{
    /** Separates the members of a multiple-cardinality response. */
    public const ITEM_SEPARATOR = '[,]';

    /** Separates the two halves of a pair, which is what matching and performance carry. */
    public const PAIR_SEPARATOR = '[.]';

    private function __construct()
    {
    }

    /**
     * Null when the learner stored nothing, which is a different fact from
     * an empty response and must stay distinguishable in the statement.
     */
    public static function of(mixed $answer): ?string
    {
        if ($answer === null) {
            return null;
        }

        return self::render($answer, 0);
    }

    private static function render(mixed $value, int $depth): string
    {
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_float($value)) {
            // No exponent and no trailing zeroes: an LRS compares response
            // strings literally, so 3.0 and 3 must not be two answers.
            return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        }

        if (\is_string($value)) {
            return $value;
        }

        if (\is_array($value)) {
            return self::renderArray($value, $depth);
        }

        // Anything else (an object, a resource) has no response format and
        // is reported as absent rather than as a PHP type name.
        return '';
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function renderArray(array $value, int $depth): string
    {
        // Two levels is the whole of the format: a list of pairs. Deeper
        // than that has no xAPI rendering, and flattening it further would
        // invent one.
        if ($depth >= 2) {
            return '';
        }

        $parts = [];

        foreach ($value as $key => $member) {
            $rendered = self::render($member, $depth + 1);

            $parts[] = \is_string($key)
                ? $key . self::PAIR_SEPARATOR . $rendered
                : $rendered;
        }

        return implode(self::ITEM_SEPARATOR, $parts);
    }
}
