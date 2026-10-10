<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Schema;

final class Table
{
    /**
     * @param array<string, Column> $columns keyed by column name, in order
     * @param list<Index>           $indexes
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly array $indexes = [],
    ) {
    }

    public function column(string $name): Column
    {
        return $this->columns[$name]
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw new \LogicException(sprintf('Table "%s" has no column "%s".', $this->name, $name));
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[$name]);
    }
}
