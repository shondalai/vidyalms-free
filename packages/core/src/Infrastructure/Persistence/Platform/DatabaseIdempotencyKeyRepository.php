<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\IdempotencyKeyRepository;
use Shondalai\VidyaLMS\Core\Domain\Platform\IdempotencyRecord;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseIdempotencyKeyRepository implements IdempotencyKeyRepository
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function insertClaim(string $scope, string $key, \DateTimeImmutable $expiresAt): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        try {
            $this->database->insert('idempotency_keys', [
                'scope' => $scope,
                'idem_key' => $key,
                'status' => IdempotencyRecord::STATUS_CLAIMED,
                'outcome' => null,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $failure) {
            if ($this->find($scope, $key) !== null) {
                return false;
            }

            throw $failure;
        }

        return true;
    }

    public function find(string $scope, string $key): ?IdempotencyRecord
    {
        $row = $this->database->selectOne(
            Query::from('idempotency_keys')
                ->where('scope', '=', $scope)
                ->where('idem_key', '=', $key),
        );

        if ($row === null) {
            return null;
        }

        return new IdempotencyRecord(
            $scope,
            $key,
            (string) ($row['status'] ?? IdempotencyRecord::STATUS_CLAIMED),
            $this->outcomeFrom($row['outcome'] ?? null),
            new \DateTimeImmutable((string) ($row['expires_at'] ?? '1970-01-01 00:00:00'), new \DateTimeZone('UTC')),
        );
    }

    public function reclaim(string $scope, string $key, \DateTimeImmutable $expiresAt, \DateTimeImmutable $previousExpiry): bool
    {
        return $this->database->update('idempotency_keys', [
            'status' => IdempotencyRecord::STATUS_CLAIMED,
            'outcome' => null,
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['scope' => $scope, 'idem_key' => $key, 'expires_at' => $previousExpiry->format('Y-m-d H:i:s')]) === 1;
    }

    public function storeOutcome(string $scope, string $key, array $outcome, \DateTimeImmutable $expiresAt, \DateTimeImmutable $claimExpiry): bool
    {
        return $this->database->update('idempotency_keys', [
            'status' => IdempotencyRecord::STATUS_COMPLETED,
            'outcome' => json_encode($outcome, JSON_THROW_ON_ERROR),
            'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['scope' => $scope, 'idem_key' => $key, 'status' => IdempotencyRecord::STATUS_CLAIMED,
            'expires_at' => $claimExpiry->format('Y-m-d H:i:s')]) === 1;
    }

    public function releaseClaim(string $scope, string $key, \DateTimeImmutable $claimExpiry): void
    {
        $this->database->delete('idempotency_keys', [
            'scope' => $scope,
            'idem_key' => $key,
            'status' => IdempotencyRecord::STATUS_CLAIMED,
            'expires_at' => $claimExpiry->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function outcomeFrom(mixed $value): ?array
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $decoded = self::retainObjectShape(json_decode($value, false, 512, JSON_THROW_ON_ERROR));

        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Associative PHP arrays are convenient to callers, but decoding every
     * object as one changes {} to [] and numeric-keyed objects into lists.
     * Those are different wire contracts. Retain an object exactly where
     * its array equivalent would encode as a JSON list; ordinary maps and
     * real lists keep the existing array representation.
     */
    private static function retainObjectShape(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $members = array_map(self::retainObjectShape(...), (array) $value);

            return array_is_list($members) ? (object) $members : $members;
        }

        if (\is_array($value)) {
            return array_map(self::retainObjectShape(...), $value);
        }

        return $value;
    }
}
