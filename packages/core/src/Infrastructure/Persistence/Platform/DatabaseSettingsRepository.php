<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\Setting;
use Shondalai\VidyaLMS\Core\Domain\Platform\SettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseSettingsRepository implements SettingsRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function find(string $name): ?Setting
    {
        $row = $this->database->selectOne(
            Query::from('settings')->where('name', '=', $name),
        );

        if ($row === null) {
            return null;
        }

        $raw = $row['value'] ?? null;
        $value = \is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;

        return new Setting($name, $value);
    }

    public function set(string $name, mixed $value): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->database->upsert(
            'settings',
            [
                'name' => $name,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['name'],
            ['value', 'updated_at'],
        );
    }

    public function setIfAbsent(string $name, mixed $value): Setting
    {
        return $this->database->transactional(function () use ($name, $value): Setting {
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            try {
                $this->database->insert('settings', ['name' => $name, 'value' => json_encode($value, JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now]);
            } catch (\Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation) {
                // Another request installed the setting. Read its value, never overwrite it.
            }
            $query = Query::from('settings')->where('name', '=', $name);
            $row = $this->database instanceof \Shondalai\VidyaLMS\Core\Port\Database\SupportsLockingReads
                ? $this->database->selectOneForUpdate($query) : $this->database->selectOne($query);
            if ($row === null || !is_string($row['value'] ?? null)) {
                throw new \RuntimeException('The setting could not be initialised.');
            }
            return new Setting($name, json_decode($row['value'], true, 512, JSON_THROW_ON_ERROR));
        });
    }
}
