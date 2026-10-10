<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Schema;

final class Column
{
    public function __construct(
        public readonly string $name,
        public readonly ColumnType $type,
        public readonly ?int $length = null,
        public readonly ?int $precision = null,
        public readonly ?int $scale = null,
        public readonly bool $nullable = false,
        public readonly bool $hasDefault = false,
        public readonly string|int|float|bool|null $default = null,
    ) {
    }

    /**
     * Parses a compact spec: "string:255", "?datetime", "decimal:5,2",
     * "uint". A leading question mark marks the column nullable.
     */
    public static function fromSpec(string $name, string $spec, bool $hasDefault = false, string|int|float|bool|null $default = null): self
    {
        $nullable = str_starts_with($spec, '?');
        $spec = ltrim($spec, '?');

        [$typeName, $args] = array_pad(explode(':', $spec, 2), 2, null);
        $type = ColumnType::tryFrom((string) $typeName)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw new \LogicException(sprintf('Unknown column type "%s" for column "%s".', (string) $typeName, $name));

        $length = null;
        $precision = null;
        $scale = null;

        if ($type === ColumnType::Text) {
            if ($args === null || preg_match('/^[0-9]+$/', $args) !== 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \LogicException(sprintf('Column "%s": string columns need a length ("string:255").', $name));
            }

            $length = (int) $args;
        } elseif ($type === ColumnType::Decimal) {
            if ($args === null || preg_match('/^([0-9]+),([0-9]+)$/', $args, $matches) !== 1) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw new \LogicException(sprintf('Column "%s": decimal columns need precision and scale ("decimal:8,2").', $name));
            }

            $precision = (int) $matches[1];
            $scale = (int) $matches[2];
        } elseif ($args !== null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \LogicException(sprintf('Column "%s": type "%s" takes no arguments.', $name, $type->value));
        }

        return new self($name, $type, $length, $precision, $scale, $nullable, $hasDefault, $default);
    }
}
