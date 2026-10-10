<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Schema;

final class Index
{
    /**
     * @param list<string> $columns
     */
    public function __construct(
        public readonly string $name,
        public readonly array $columns,
        public readonly bool $unique = false,
    ) {
        if ($columns === []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new \LogicException(sprintf('Index "%s" needs at least one column.', $name));
        }
    }
}
