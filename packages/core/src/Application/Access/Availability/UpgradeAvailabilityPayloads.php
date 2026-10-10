<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTreeCodec;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Availability\DeclaresReferences;
use Shondalai\VidyaLMS\Core\Registry\Context\UpgradeContext;

/**
 * The batch half of the upgrader chain: rewriting stored documents.
 *
 * The read path upgrades in memory and never writes, because a read that
 * writes is a read that fails on a replica and breaks the query budget. This
 * is the job that makes the stored rows current, and it is an indexed query
 * rather than a scan for the reason the mirrored version column exists:
 * "every rule containing a `date` leaf below version 3" is a predicate on
 * `availability_rule_refs.(type, payload_version)` across all three owner
 * types at once, on MySQL, MariaDB and SQLite alike.
 *
 * It runs per provider, so each condition migrates on its own chain: a single
 * document-level version would force every provider to migrate together the
 * first time any one of them gained a field, which means an unrelated
 * first-party change breaks a third-party condition.
 */
final class UpgradeAvailabilityPayloads
{
    /** How many owner documents one run rewrites, so a run is bounded. */
    public const DEFAULT_LIMIT = 200;

    /**
     * @var array<string, array{table: string, column: string, surface: AvailabilitySurface}>
     */
    private const OWNERS = [
        SqlAvailabilityRuleRepository::OWNER_AVAILABILITY_RULE => [
            'table' => 'availability_rules',
            'column' => 'rule',
            'surface' => AvailabilitySurface::ContentGating,
        ],
        SqlAvailabilityRuleRepository::OWNER_NOTIFICATION_RULE => [
            'table' => 'notification_rules',
            'column' => 'conditions',
            'surface' => AvailabilitySurface::NotificationRule,
        ],
        SqlAvailabilityRuleRepository::OWNER_BADGE => [
            'table' => 'badges',
            'column' => 'criteria',
            'surface' => AvailabilitySurface::AwardCriteria,
        ],
    ];

    public function __construct(
        private readonly SqlAvailabilityRuleRepository $rules,
        private readonly AvailabilityConditionRegistry $conditions,
        private readonly DatabasePort $database,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return int how many owner documents were rewritten
     */
    public function execute(int $limit = self::DEFAULT_LIMIT): int
    {
        $owners = [];

        foreach ($this->conditions->all() as $condition) {
            $current = $condition->currentShape();

            if ($current <= 1) {
                continue;
            }

            $rows = $this->database->selectAll(
                Query::from('availability_rule_refs')
                    ->select(['owner_type', 'owner_id'])
                    ->where('type', '=', $condition->key())
                    ->where('payload_version', '<', $current)
                    ->orderBy('owner_id')
                    ->limit($limit),
            );

            foreach ($rows as $row) {
                $ownerType = (string) ($row['owner_type'] ?? '');
                $ownerId = (int) ($row['owner_id'] ?? 0);

                if ($ownerType !== '' && $ownerId > 0) {
                    $owners[$ownerType . '|' . $ownerId] = [$ownerType, $ownerId];
                }
            }
        }

        $rewritten = 0;

        foreach (\array_slice($owners, 0, $limit) as [$ownerType, $ownerId]) {
            $rewritten += $this->upgradeOwner($ownerType, $ownerId) ? 1 : 0;
        }

        return $rewritten;
    }

    private function upgradeOwner(string $ownerType, int $ownerId): bool
    {
        $owner = self::OWNERS[$ownerType] ?? null;

        if ($owner === null) {
            return false;
        }

        $row = $this->database->selectOne(
            Query::from($owner['table'])->select(['id', $owner['column']])->where('id', '=', $ownerId),
        );

        $raw = $row[$owner['column']] ?? null;

        if (!\is_string($raw) || $raw === '') {
            return false;
        }

        $decoded = json_decode($raw, true);

        if (!\is_array($decoded)) {
            return false;
        }

        /** @var array<string, mixed> $decoded */
        $tree = AvailabilityTreeCodec::decode($decoded);
        $changed = false;
        $versions = [];

        $upgraded = $tree->mapLeaves(
            function (string $type, array $payload, int $version) use (&$changed, &$versions): array {
                if (!$this->conditions->has($type)) {
                    return $payload;
                }

                $condition = $this->conditions->get($type);
                $current = $condition->currentShape();

                if ($version >= $current) {
                    return $payload;
                }

                try {
                    while ($version < $current) {
                        $payload = $condition->upgrade($payload, $version, UpgradeContext::create($type, $version));
                        ++$version;
                    }
                } catch (\Throwable $failure) {
                    $this->logger->warning('VidyaLMS availability payload upgrade failed; the leaf is left as stored.', [
                        'provider' => $type,
                        'exception' => $failure,
                    ]);

                    return $payload;
                }

                $changed = true;
                $versions[$type] = $current;

                return $payload;
            },
        );

        if (!$changed) {
            return false;
        }

        // The leaf versions the codec writes come from the leaf objects, and
        // mapLeaves preserves them, so the rewritten document is re-encoded
        // from a tree whose leaves carry the new versions. Re-decoding the
        // encoded form is what stamps them.
        $encoded = AvailabilityTreeCodec::encode($upgraded);
        $this->database->update($owner['table'], [
            $owner['column'] => json_encode($this->stampVersions($encoded, $versions), \JSON_THROW_ON_ERROR),
        ], ['id' => $ownerId]);

        $this->rewriteReferences($ownerType, $ownerId, $owner['surface']);

        return true;
    }

    /**
     * @param array<string, mixed>|null $document
     * @param array<string, int>        $versions provider key to its current shape
     *
     * @return array<string, mixed>|null
     */
    private function stampVersions(?array $document, array $versions): ?array
    {
        if ($document === null) {
            return null;
        }

        $children = $document['c'] ?? null;

        if (!\is_array($children)) {
            return $document;
        }

        foreach ($children as $index => $child) {
            if (!\is_array($child)) {
                continue;
            }

            if (isset($child['op'])) {
                /** @var array<string, mixed> $child */
                $children[$index] = $this->stampVersions($child, $versions);
                continue;
            }

            $type = $child['type'] ?? null;

            if (\is_string($type) && isset($versions[$type]) && \is_array($child['payload'] ?? null)) {
                $child['payload']['_v'] = $versions[$type];
                $children[$index] = $child;
            }
        }

        $document['c'] = $children;

        return $document;
    }

    private function rewriteReferences(string $ownerType, int $ownerId, AvailabilitySurface $surface): void
    {
        $owner = self::OWNERS[$ownerType];
        $row = $this->database->selectOne(
            Query::from($owner['table'])->select(['id', $owner['column']])->where('id', '=', $ownerId),
        );

        $raw = $row[$owner['column']] ?? null;
        $decoded = \is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        if (!\is_array($decoded)) {
            return;
        }

        /** @var array<string, mixed> $decoded */
        $references = [];

        foreach (AvailabilityTreeCodec::decode($decoded)->leaves() as $path => $leaf) {
            $subjects = [];

            if ($this->conditions->has($leaf->type)) {
                $condition = $this->conditions->get($leaf->type);
                $subjects = $condition instanceof DeclaresReferences ? $condition->dependsOn($leaf->payload) : [];
            }

            if ($subjects === []) {
                $references[] = [
                    'leaf_path' => $path,
                    'type' => $leaf->type,
                    'payload_version' => $leaf->payloadVersion,
                    'ref_type' => null,
                    'ref_id' => null,
                ];
                continue;
            }

            foreach ($subjects as $subject) {
                $references[] = [
                    'leaf_path' => $path,
                    'type' => $leaf->type,
                    'payload_version' => $leaf->payloadVersion,
                    'ref_type' => $subject->type,
                    'ref_id' => $subject->id,
                ];
            }
        }

        $this->rules->replaceReferences($ownerType, $ownerId, $surface, $references);
    }
}
