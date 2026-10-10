<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\ConfigurationRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\UniqueConstraintViolation;

/** One bounded read for the configuration and its compare-and-swap revision. */
final class DatabaseConfigurationRepository implements ConfigurationRepository
{
    public const VALUES = 'platform.configuration';
    public const REVISION = 'platform.configuration.revision';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly string $namespace = self::VALUES,
    ) {
    }

    public function read(): ?array
    {
        $rows = $this->database->selectAll(Query::from('settings')->whereIn('name', [$this->namespace, $this->namespace . '.revision']));
        $values = [];
        foreach ($rows as $row) {
            $values[(string) $row['name']] = json_decode((string) $row['value'], true, 512, JSON_THROW_ON_ERROR);
        }
        if ($values === []) {
            return null;
        }
        if (
            !isset($values[$this->namespace . '.revision']) || !\is_int($values[$this->namespace . '.revision'])
            || !\is_array($values[$this->namespace] ?? null)
        ) {
            throw new \RuntimeException('The VidyaLMS configuration record is incomplete.');
        }
        return ['revision' => $values[$this->namespace . '.revision'], 'values' => $values[$this->namespace]];
    }

    public function initialize(array $values): void
    {
        try {
            $this->database->transactional(function () use ($values): void {
                $now = $this->clock->now()->format('Y-m-d H:i:s');
                foreach ([$this->namespace . '.revision' => 1, $this->namespace => $values] as $name => $value) {
                    $this->database->insert('settings', [
                        'name' => $name, 'value' => json_encode($value, JSON_THROW_ON_ERROR),
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            });
        } catch (UniqueConstraintViolation $error) {
            // Another request completed the one-time import first. Never overwrite it.
            if ($error->table !== 'settings' || $this->read() === null) {
                throw $error;
            }
        }
    }

    public function replace(int $expectedRevision, array $values): void
    {
        $this->database->transactional(function () use ($expectedRevision, $values): void {
            $now = $this->clock->now()->format('Y-m-d H:i:s');
            // A numeric JSON revision avoids comparing JSON objects whose key order/spacing the engine may normalize.
            $changed = $this->database->update('settings', [
                'value' => json_encode($expectedRevision + 1, JSON_THROW_ON_ERROR), 'updated_at' => $now,
            ], [
                'name' => $this->namespace . '.revision', 'value' => $expectedRevision,
            ]);
            if ($changed !== 1) {
                throw new ConflictException('Settings were changed by another administrator. Reload before saving.');
            }
            $this->database->update('settings', ['value' => json_encode($values, JSON_THROW_ON_ERROR), 'updated_at' => $now], [
                'name' => $this->namespace,
            ]);
        });
    }
}
