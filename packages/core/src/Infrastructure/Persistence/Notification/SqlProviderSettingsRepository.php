<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification;

use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Provider configuration, for every registry, at any scope.
 *
 * One table so that configuring a provider is a row rather than a migration.
 * A policy needing a dedicated column would make the ninth policy a schema
 * change, which is the failure the registry exists to prevent, and it
 * privileges whichever providers shipped first. That is why the quiet-hours
 * mode and the one-time-only flag are rule-scoped rows here and not columns
 * on the rule.
 *
 * Resolution is narrowest first: rule, then cohort, then course, then site.
 */
final class SqlProviderSettingsRepository
{
    public const TABLE = 'provider_settings';

    public const SCOPE_SITE = 'site';
    public const SCOPE_COURSE = 'course';
    public const SCOPE_COHORT = 'cohort';
    public const SCOPE_RULE = 'rule';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return list<string> narrowest first
     */
    public static function scopeOrder(): array
    {
        return [self::SCOPE_RULE, self::SCOPE_COHORT, self::SCOPE_COURSE, self::SCOPE_SITE];
    }

    /**
     * The effective parameters for one provider, merged narrowest wins.
     *
     * @param array<string, int> $scopeIds scope type to id, for the scopes in play
     *
     * @return array<string, mixed>
     */
    public function resolve(string $registryKey, string $providerKey, array $scopeIds = []): array
    {
        $merged = [];

        foreach (array_reverse(self::scopeOrder()) as $scopeType) {
            $scopeId = $scopeType === self::SCOPE_SITE ? 0 : ($scopeIds[$scopeType] ?? null);

            if ($scopeId === null) {
                continue;
            }

            $params = $this->find($registryKey, $providerKey, $scopeType, $scopeId);

            if ($params !== null) {
                $merged = array_merge($merged, $params);
            }
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $registryKey, string $providerKey, string $scopeType, int $scopeId): ?array
    {
        return $this->record($registryKey, $providerKey, $scopeType, $scopeId)?->params;
    }

    /**
     * The whole row, not only its parameter bag.
     *
     * `secret_ref` and `enabled` are real columns with a stated purpose, and
     * before this method existed nothing in the core read either of them:
     * find() selected `params` alone and save() wrote four columns, so the
     * column whose whole reason is that a leaked signing secret lets an
     * attacker forge entitlement grants was inert, and `enabled` disabled
     * nothing. A gateway package would have had to write both with SQL of its
     * own and parse the reference grammar itself, which is three
     * implementations of the one value whose leak is unrecoverable.
     */
    public function record(
        string $registryKey,
        string $providerKey,
        string $scopeType,
        int $scopeId,
    ): ?ProviderSettingsRecord {
        $row = $this->database->selectOne(
            Query::from(self::TABLE)
                ->select(['params', 'params_version', 'secret_ref', 'enabled'])
                ->where('registry_key', '=', $registryKey)
                ->where('provider_key', '=', $providerKey)
                ->where('scope_type', '=', $scopeType)
                ->where('scope_id', '=', $scopeId),
        );

        if ($row === null) {
            return null;
        }

        $raw = $row['params'] ?? null;
        $decoded = \is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];

        /** @var array<string, mixed> $params */
        $params = \is_array($decoded) ? $decoded : [];
        $secretRef = $row['secret_ref'] ?? null;

        return new ProviderSettingsRecord(
            $params,
            (int) ($row['params_version'] ?? 1),
            \is_string($secretRef) && $secretRef !== '' ? $secretRef : null,
            // A row written before the column existed reads null, and a
            // provider a site never disabled is enabled. Null is not
            // "disabled", or an upgrade would silently switch every
            // configured provider off.
            !isset($row['enabled']) || (bool) $row['enabled'],
        );
    }

    /**
     * The reference to the secret this provider signs or authenticates with,
     * resolved narrowest scope first, or null when no scope in play names one.
     *
     * @param array<string, int> $scopeIds
     */
    public function secretRef(string $registryKey, string $providerKey, array $scopeIds = []): ?string
    {
        foreach (self::scopeOrder() as $scopeType) {
            $scopeId = $scopeType === self::SCOPE_SITE ? 0 : ($scopeIds[$scopeType] ?? null);

            if ($scopeId === null) {
                continue;
            }

            $record = $this->record($registryKey, $providerKey, $scopeType, $scopeId);

            if ($record?->secretRef !== null) {
                return $record->secretRef;
            }
        }

        return null;
    }

    /**
     * Whether this provider is switched on for the scopes in play.
     *
     * Narrowest wins, and an absent row is enabled: a provider a site owner
     * has never configured is a provider that works, which is what every
     * registry's free default already assumes.
     *
     * @param array<string, int> $scopeIds
     */
    public function isEnabled(string $registryKey, string $providerKey, array $scopeIds = []): bool
    {
        foreach (self::scopeOrder() as $scopeType) {
            $scopeId = $scopeType === self::SCOPE_SITE ? 0 : ($scopeIds[$scopeType] ?? null);

            if ($scopeId === null) {
                continue;
            }

            $record = $this->record($registryKey, $providerKey, $scopeType, $scopeId);

            if ($record !== null) {
                return $record->enabled;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $params
     * @param ?string              $secretRef a `env:NAME` or `store:KEY` pointer, never a value.
     *                                        Null leaves whatever is stored alone, which is the
     *                                        same rule the write-only secret fields follow: a
     *                                        screen that never sees a secret cannot blank one by
     *                                        saving the form.
     * @param ?bool                $enabled   null leaves the stored state alone
     */
    public function save(
        string $registryKey,
        string $providerKey,
        string $scopeType,
        int $scopeId,
        array $params,
        ?int $updatedBy = null,
        ?string $secretRef = null,
        ?bool $enabled = null,
    ): void {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $existing = $this->record($registryKey, $providerKey, $scopeType, $scopeId);

        $this->database->upsert(
            self::TABLE,
            [
                'registry_key' => $registryKey,
                'provider_key' => $providerKey,
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
                'params' => json_encode($params, \JSON_THROW_ON_ERROR),
                'params_version' => 1,
                'updated_by' => $updatedBy,
                'secret_ref' => $secretRef ?? $existing?->secretRef,
                'enabled' => ($enabled ?? ($existing === null ? true : $existing->enabled)) ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['registry_key', 'provider_key', 'scope_type', 'scope_id'],
            ['params', 'params_version', 'updated_by', 'secret_ref', 'enabled', 'updated_at'],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forRegistry(string $registryKey, string $scopeType, int $scopeId): array
    {
        return $this->database->selectAll(
            Query::from(self::TABLE)
                ->where('registry_key', '=', $registryKey)
                ->where('scope_type', '=', $scopeType)
                ->where('scope_id', '=', $scopeId),
        );
    }
}
