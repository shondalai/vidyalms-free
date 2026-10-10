<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Settings;

/**
 * The contract's SettingsField, built in one place.
 *
 * Every declaration would otherwise be a hand-written associative array, and
 * a hand-written shape drifts: the route was already emitting a "text" type
 * the contract's enum does not contain, which a form renders as a plain input
 * by accident rather than by agreement.
 *
 * `secret()` is the one with a rule behind it. A secret field is declared so
 * an administrator has somewhere to type it, is never returned once stored,
 * and reports only whether one is set, because a settings screen that echoed
 * a shared secret would leave it in every browser cache that opened the page.
 */
final class SettingsFields
{
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_NUMBER = 'number';
    public const TYPE_STRING = 'string';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_DURATION = 'duration';
    public const TYPE_ENUM = 'enum';
    public const TYPE_OBJECT = 'object';

    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function string(
        string $key,
        string $label,
        ?string $default = null,
        ?string $description = null,
        bool $required = false,
    ): array {
        return self::of($key, $label, self::TYPE_STRING, $default, $description, $required);
    }

    /**
     * @return array<string, mixed>
     */
    public static function integer(
        string $key,
        string $label,
        ?int $default = null,
        ?string $description = null,
        ?int $minimum = null,
        ?int $maximum = null,
    ): array {
        $field = self::of($key, $label, self::TYPE_INTEGER, $default, $description, false);
        $field['minimum'] = $minimum;
        $field['maximum'] = $maximum;

        return $field;
    }

    /**
     * @return array<string, mixed>
     */
    public static function boolean(string $key, string $label, ?bool $default = null, ?string $description = null): array
    {
        return self::of($key, $label, self::TYPE_BOOLEAN, $default, $description, false);
    }

    /**
     * A field that goes in and never comes back.
     *
     * @return array<string, mixed>
     */
    public static function secret(string $key, string $label, ?string $description = null): array
    {
        $field = self::of($key, $label, self::TYPE_STRING, null, $description, false);
        $field['write_only'] = true;

        return $field;
    }

    /**
     * @param list<array{key: string, label: string}> $options
     *
     * @return array<string, mixed>
     */
    public static function enum(
        string $key,
        string $label,
        array $options,
        ?string $default = null,
        ?string $description = null,
    ): array {
        $field = self::of($key, $label, self::TYPE_ENUM, $default, $description, false);
        $field['options'] = $options;

        return $field;
    }

    /**
     * @return array<string, mixed>
     */
    private static function of(
        string $key,
        string $label,
        string $type,
        mixed $default,
        ?string $description,
        bool $required,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'type' => $type,
            'default' => $default,
            'current' => null,
            'write_only' => false,
            'required' => $required,
        ];
    }
}
