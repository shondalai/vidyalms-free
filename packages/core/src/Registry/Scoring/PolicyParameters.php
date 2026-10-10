<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Scoring;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * The parameter rules every policy shares.
 *
 * A policy parameter has exactly one home. mapping owns default_value,
 * lower_bound and upper_bound; scalar owns tolerance; numeric owns
 * accepted[].tolerance. Any of those appearing inside policy.params is
 * refused with a message naming the correct location, because the same
 * authored item scoring two different marks depending on which of two
 * locations the engine reads is not a documentation problem.
 */
final class PolicyParameters
{
    public const FLOOR_FRACTION = 'floor_fraction';

    /** Parameter name to the object that actually owns it. */
    private const FOREIGN_HOMES = [
        'default_value' => 'the mapping object',
        'lower_bound' => 'the mapping object',
        'upper_bound' => 'the mapping object',
        'tolerance' => 'the scalar or numeric accepted band',
    ];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function floor(array $params): float
    {
        $floor = $params[self::FLOOR_FRACTION] ?? 0.0;

        return \is_int($floor) || \is_float($floor) ? (float) $floor : 0.0;
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function rejectForeignHomes(array $params): void
    {
        foreach (self::FOREIGN_HOMES as $name => $home) {
            if (\array_key_exists($name, $params)) {
                throw ValidationException::forField(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    'policy.params.' . $name,
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    sprintf('"%s" belongs to %s, not to the policy parameters.', $name, $home),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $allowed
     */
    public static function rejectUnknown(array $params, array $allowed, string $policyKey): void
    {
        self::rejectForeignHomes($params);

        foreach (array_keys($params) as $name) {
            if (!\in_array($name, $allowed, true) && $name !== self::FLOOR_FRACTION) {
                throw ValidationException::forField(
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    'policy.params.' . $name,
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    sprintf('The "%s" policy takes no "%s" parameter.', $policyKey, $name),
                );
            }
        }

        $floor = $params[self::FLOOR_FRACTION] ?? 0.0;

        if (!\is_int($floor) && !\is_float($floor)) {
            throw ValidationException::forField('policy.params.floor_fraction', 'The floor fraction must be a number.');
        }

        if ((float) $floor > 1.0) {
            throw ValidationException::forField('policy.params.floor_fraction', 'The floor fraction cannot exceed 1.');
        }
    }

    /**
     * The schema fragment every policy's paramsSchema() starts from.
     *
     * @param array<string, array<string, mixed>> $properties
     *
     * @return array<string, mixed>
     */
    public static function schema(array $properties): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                self::FLOOR_FRACTION => [
                    'type' => 'number',
                    'maximum' => 1,
                    'default' => 0,
                    'description' => 'The lowest fraction this policy may award. Never below 0 unless '
                        . 'the quiz policy snapshot enables negative marking.',
                ],
            ] + $properties,
        ];
    }
}
