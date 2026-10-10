<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Platform;

interface SettingsRepository
{
    public function find(string $name): ?Setting;

    /**
     * Inserts the setting or replaces the stored value when the name exists.
     */
    public function set(string $name, mixed $value): void;

    /** Atomically initialise a value and return the winner, without replacing it. */
    public function setIfAbsent(string $name, mixed $value): Setting;
}
