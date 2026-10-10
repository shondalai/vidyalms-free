<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access\Availability;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTreeCodec;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\SqlAvailabilityRuleRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Availability\AvailabilityConditionRegistry;
use Shondalai\VidyaLMS\Core\Registry\Availability\DeclaresReferences;

/**
 * Repoints every stored tree that names an entity that has been copied.
 *
 * It walks **every owner type** rather than the availability rules alone,
 * which is the whole reason the reference table is polymorphic in its owner:
 * a badge criterion or a notification condition naming a lesson id is
 * remapped by a course copy exactly as a content gate is, and a walk wired to
 * one owner leaves two thirds of the stored trees pointing at the original
 * course. A leaf that cannot resolve its referent afterwards evaluates to
 * blocked with a description and never to available.
 *
 * The reference table is the index rather than a scan of every document: "the
 * rules that reference lesson 88" is one indexed query on it and a JSON path
 * scan without it, on every engine this product targets.
 */
final class RemapAvailabilityReferences
{
    /**
     * The tables and columns each owner type's document lives in, so adding
     * an owner is an entry here rather than a fourth code path.
     *
     * @var array<string, array{table: string, column: string, version: string, surface: AvailabilitySurface}>
     */
    private const OWNERS = [
        SqlAvailabilityRuleRepository::OWNER_AVAILABILITY_RULE => [
            'table' => 'availability_rules',
            'column' => 'rule',
            'version' => 'rule_version',
            'surface' => AvailabilitySurface::ContentGating,
        ],
        SqlAvailabilityRuleRepository::OWNER_NOTIFICATION_RULE => [
            'table' => 'notification_rules',
            'column' => 'conditions',
            'version' => 'conditions_version',
            'surface' => AvailabilitySurface::NotificationRule,
        ],
        SqlAvailabilityRuleRepository::OWNER_BADGE => [
            'table' => 'badges',
            'column' => 'criteria',
            'version' => 'criteria_version',
            'surface' => AvailabilitySurface::AwardCriteria,
        ],
    ];

    public function __construct(
        private readonly SqlAvailabilityRuleRepository $rules,
        private readonly AvailabilityConditionRegistry $conditions,
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * Rewrites every owner that names one of the mapped entities.
     *
     * @param array<string, array<int, int>> $map old id to new id, keyed by reference type
     *
     * @return int how many owner documents were rewritten
     */
    public function execute(array $map): int
    {
        $owners = [];

        foreach ($map as $refType => $ids) {
            foreach (array_keys($ids) as $oldId) {
                foreach ($this->rules->referencesTo((string) $refType, (int) $oldId) as $row) {
                    $ownerType = (string) ($row['owner_type'] ?? '');
                    $ownerId = (int) ($row['owner_id'] ?? 0);

                    if ($ownerType !== '' && $ownerId > 0) {
                        $owners[$ownerType . '|' . $ownerId] = [$ownerType, $ownerId];
                    }
                }
            }
        }

        $rewritten = 0;

        foreach ($owners as [$ownerType, $ownerId]) {
            $rewritten += $this->remapOwner($ownerType, $ownerId, $map) ? 1 : 0;
        }

        return $rewritten;
    }

    /**
     * @param array<string, array<int, int>> $map
     */
    private function remapOwner(string $ownerType, int $ownerId, array $map): bool
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
        $references = [];
        $changed = false;

        $remapped = $tree->mapLeaves(function (string $type, array $payload, int $version) use ($map, &$changed): array {
            if (!$this->conditions->has($type)) {
                return $payload;
            }

            $condition = $this->conditions->get($type);

            if (!$condition instanceof DeclaresReferences) {
                return $payload;
            }

            $rewritten = $condition->remapReferences($payload, $map);

            if ($rewritten !== $payload) {
                $changed = true;
            }

            return $rewritten;
        });

        if (!$changed) {
            return false;
        }

        $this->database->update($owner['table'], [
            $owner['column'] => json_encode(AvailabilityTreeCodec::encode($remapped), \JSON_THROW_ON_ERROR),
        ], ['id' => $ownerId]);

        // The reference rows follow the document, or the next remap and the
        // next batch upgrade both read the old ids.
        foreach ($remapped->leaves() as $path => $leaf) {
            if (!$this->conditions->has($leaf->type)) {
                continue;
            }

            $condition = $this->conditions->get($leaf->type);
            $subjects = $condition instanceof DeclaresReferences ? $condition->dependsOn($leaf->payload) : [];

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

        $this->rules->replaceReferences($ownerType, $ownerId, $owner['surface'], $references);

        return true;
    }
}
