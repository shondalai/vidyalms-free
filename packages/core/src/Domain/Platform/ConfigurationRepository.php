<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

interface ConfigurationRepository
{
    /** @return array{revision: int, values: array<string, mixed>}|null */
    public function read(): ?array;

    /** @param array<string, mixed> $values */
    public function initialize(array $values): void;

    /** @param array<string, mixed> $values */
    public function replace(int $expectedRevision, array $values): void;
}
