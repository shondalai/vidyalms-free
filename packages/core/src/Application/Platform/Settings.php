<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\SettingsRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Typed access to the settings table. A stored null is a value, not an
 * absence: only a missing name falls back to the default.
 */
final class Settings
{
    public function __construct(
        private readonly SettingsRepository $settings,
    ) {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $setting = $this->settings->find($name);

        return $setting === null ? $default : $setting->value;
    }

    public function getString(string $name, string $default = ''): string
    {
        $value = $this->get($name);

        return \is_string($value) ? $value : $default;
    }

    public function getInt(string $name, int $default = 0): int
    {
        $value = $this->get($name);

        return \is_int($value) ? $value : $default;
    }

    /**
     * The same reading, but a whole number written as a string counts.
     *
     * Settings rows are JSON, and every writer decides for itself whether a
     * number arrives as 1 or as "1": a form posts strings, a CLI argument is a
     * string, and a hand-edited row is whatever somebody typed. getInt() is
     * deliberately strict, because a setting that silently accepted "true" or
     * "12 users" would be a setting nobody could reason about, but a row a
     * screen wrote is not a coercion hazard and refusing it is how a
     * configured value reads as unset.
     *
     * The failure this closes is concrete: `commerce.background_actor_user_id`
     * stored as the string "1" resolved to nobody, so a background grant was
     * silently disabled and the order was left paid and unfulfilled with the
     * configuration reported as if it had never been made. Nothing writes that
     * row yet, so whichever screen or CLI lands first would otherwise decide
     * the type by accident.
     */
    public function getIntish(string $name, int $default = 0): int
    {
        $value = $this->get($name);

        if (\is_int($value)) {
            return $value;
        }

        // A whole number and nothing else: no float, no leading plus, no
        // trailing text, no "1e3". A value this cannot read is the default,
        // which is the same answer getInt() gives.
        return \is_string($value) && preg_match('/^-?[0-9]{1,18}$/D', trim($value)) === 1
            ? (int) trim($value)
            : $default;
    }

    public function getBool(string $name, bool $default = false): bool
    {
        $value = $this->get($name);

        return \is_bool($value) ? $value : $default;
    }

    /**
     * @param array<mixed> $default
     *
     * @return array<mixed>
     */
    public function getArray(string $name, array $default = []): array
    {
        $value = $this->get($name);

        return \is_array($value) ? $value : $default;
    }

    public function set(string $name, mixed $value): void
    {
        if (trim($name) === '') {
            throw ValidationException::forField('name', 'A setting name is required.');
        }

        $this->settings->set($name, $value);
    }

    public function initialiseString(string $name, string $value): string
    {
        $setting = $this->settings->setIfAbsent($name, $value);
        if (!is_string($setting->value) || $setting->value === '') {
            throw new \RuntimeException('The stored setting is not a nonempty string.');
        }
        return $setting->value;
    }
}
