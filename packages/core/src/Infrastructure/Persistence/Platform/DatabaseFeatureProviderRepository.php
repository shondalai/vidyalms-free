<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\FeatureProvider;
use Shondalai\VidyaLMS\Core\Domain\Platform\FeatureProviderRepository;
use Shondalai\VidyaLMS\Core\Domain\Platform\InactiveProviderCounting;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class DatabaseFeatureProviderRepository implements FeatureProviderRepository, InactiveProviderCounting
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function record(
        string $extensionPoint,
        string $providerKey,
        string $package,
        ?string $conformanceResult = null,
        bool $flagged = false,
    ): void {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->database->upsert(
            'feature_providers',
            [
                'extension_point' => $extensionPoint,
                'provider_key' => $providerKey,
                'package' => $package,
                'active' => 1,
                'conformance_result' => $conformanceResult,
                'flagged' => $flagged ? 1 : 0,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['extension_point', 'provider_key'],
            ['package', 'active', 'conformance_result', 'flagged', 'updated_at'],
        );
    }

    public function listActive(): array
    {
        $rows = $this->database->selectAll(
            Query::from('feature_providers')
                ->where('active', '=', 1)
                ->orderBy('extension_point')
                ->orderBy('provider_key'),
        );

        return array_map(
            static fn (array $row): FeatureProvider => new FeatureProvider(
                (string) ($row['extension_point'] ?? ''),
                (string) ($row['provider_key'] ?? ''),
                (string) ($row['package'] ?? ''),
                (int) ($row['active'] ?? 0) === 1,
                ($row['conformance_result'] ?? null) === null ? null : (string) $row['conformance_result'],
                (int) ($row['flagged'] ?? 0) === 1,
            ),
            $rows,
        );
    }

    public function packagesFor(string $extensionPoint): array
    {
        $rows = $this->database->selectAll(
            Query::from('feature_providers')
                ->select(['provider_key', 'package'])
                ->where('extension_point', '=', $extensionPoint)
                ->orderBy('provider_key'),
        );

        $packages = [];

        foreach ($rows as $row) {
            $key = (string) ($row['provider_key'] ?? '');
            $package = (string) ($row['package'] ?? '');

            if ($key !== '' && $package !== '') {
                $packages[$key] = $package;
            }
        }

        return $packages;
    }

    public function countInactive(string $package): int
    {
        return $this->database->count(
            Query::from('feature_providers')
                ->where('package', '=', $package)
                ->where('active', '=', 0),
        );
    }

    public function markInactive(string $package): int
    {
        return $this->database->update('feature_providers', [
            'active' => 0,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['package' => $package]);
    }
}
