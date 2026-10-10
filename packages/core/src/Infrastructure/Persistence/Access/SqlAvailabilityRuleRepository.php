<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access;

use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityRule;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilitySurface;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTree;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\AvailabilityTreeCodec;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * Reads and writes availability_rules and its leaf reference rows.
 *
 * JSON encoding and decoding, datetime formatting and row mapping happen here
 * and nowhere else. The reference rows are rewritten as one set per owner on
 * every save, because a partial rewrite leaves a stale row that both jobs the
 * table exists for would read as current: a course copy would remap a leaf
 * that is no longer there, and a batched payload upgrade would rewrite one.
 */
final class SqlAvailabilityRuleRepository
{
    public const OWNER_AVAILABILITY_RULE = 'availability_rule';
    public const OWNER_NOTIFICATION_RULE = 'notification_rule';
    public const OWNER_BADGE = 'badge';
    /**
     * The automation rule's entry tree and its steps' guard trees.
     *
     * tables.php added the value with the argument that it is what makes
     * "every rule that references lesson 88" an indexed query across every
     * owner and what makes a course copy remap an automation's references
     * through the identical pass. Nothing wrote it until R6 closed the
     * finding: the value existed and the rows did not.
     */
    public const OWNER_AUTOMATION_RULE = 'automation_rule';

    private const DATETIME_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function find(GateSubject $subject, AvailabilitySurface $surface): ?AvailabilityRule
    {
        $row = $this->database->selectOne(
            Query::from('availability_rules')
                ->where('subject_type', '=', $subject->type)
                ->where('subject_id', '=', $subject->id)
                ->where('surface', '=', $surface->value),
        );

        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Every rule for a set of subjects of one type, keyed by subject id. One
     * read for a whole render set, which is what the query budget needs.
     *
     * @param list<int> $subjectIds
     *
     * @return array<int, AvailabilityRule>
     */
    public function forSubjects(string $subjectType, array $subjectIds, AvailabilitySurface $surface): array
    {
        if ($subjectIds === []) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('availability_rules')
                ->where('subject_type', '=', $subjectType)
                ->whereIn('subject_id', $subjectIds)
                ->where('surface', '=', $surface->value),
        );

        $rules = [];

        foreach ($rows as $row) {
            $rules[(int) $row['subject_id']] = $this->hydrate($row);
        }

        return $rules;
    }

    /**
     * @param list<array{leaf_path: string, type: string, payload_version: int, ref_type: string|null, ref_id: int|null}> $references
     */
    public function save(AvailabilityRule $rule, array $references): AvailabilityRule
    {
        $now = $this->clock->now()->format(self::DATETIME_FORMAT);
        $encoded = AvailabilityTreeCodec::encode($rule->tree);

        $values = [
            'rule' => $encoded === null ? null : json_encode($encoded, \JSON_THROW_ON_ERROR),
            'rule_version' => $rule->tree->version,
            'default_visibility' => $rule->defaultVisibility,
            'updated_by' => $rule->updatedBy,
            'updated_at' => $now,
        ];

        $existing = $this->database->selectOne(
            Query::from('availability_rules')
                ->select(['id'])
                ->where('subject_type', '=', $rule->subject->type)
                ->where('subject_id', '=', $rule->subject->id)
                ->where('surface', '=', $rule->surface->value),
        );

        if ($existing === null) {
            $id = $this->database->insert('availability_rules', $values + [
                'subject_type' => $rule->subject->type,
                'subject_id' => $rule->subject->id,
                'surface' => $rule->surface->value,
                'created_at' => $now,
            ]);
        } else {
            $id = (int) $existing['id'];
            $this->database->update('availability_rules', $values, ['id' => $id]);
        }

        $this->replaceReferences(self::OWNER_AVAILABILITY_RULE, $id, $rule->surface, $references);

        return new AvailabilityRule(
            $id,
            $rule->subject,
            $rule->surface,
            $rule->tree,
            $rule->defaultVisibility,
            $rule->updatedBy,
            $this->clock->now(),
        );
    }

    public function delete(GateSubject $subject, AvailabilitySurface $surface): bool
    {
        $row = $this->database->selectOne(
            Query::from('availability_rules')
                ->select(['id'])
                ->where('subject_type', '=', $subject->type)
                ->where('subject_id', '=', $subject->id)
                ->where('surface', '=', $surface->value),
        );

        if ($row === null) {
            return false;
        }

        $id = (int) $row['id'];
        $this->database->delete('availability_rule_refs', [
            'owner_type' => self::OWNER_AVAILABILITY_RULE,
            'owner_id' => $id,
        ]);
        $this->database->delete('availability_rules', ['id' => $id]);

        return true;
    }

    /**
     * The one writer of the leaf reference rows, shared by all three owner
     * types. A pass wired only to the availability rule save would leave two
     * thirds of the stored trees unremapped on a course copy and invisible to
     * a batched payload upgrade.
     *
     * @param list<array{leaf_path: string, type: string, payload_version: int, ref_type: string|null, ref_id: int|null}> $references
     */
    public function replaceReferences(string $ownerType, int $ownerId, AvailabilitySurface $surface, array $references): void
    {
        $this->database->delete('availability_rule_refs', [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
        ]);

        foreach ($references as $reference) {
            $this->database->insert('availability_rule_refs', [
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'surface' => $surface->value,
                'leaf_path' => $reference['leaf_path'],
                'type' => $reference['type'],
                'payload_version' => $reference['payload_version'],
                'ref_type' => $reference['ref_type'],
                'ref_id' => $reference['ref_id'],
            ]);
        }
    }

    /**
     * Every rule of one surface that names a given entity, which is what a
     * course copy walks and what the cycle check reads.
     *
     * @return list<array<string, mixed>>
     */
    public function referencesTo(string $refType, int $refId): array
    {
        return $this->database->selectAll(
            Query::from('availability_rule_refs')
                ->where('ref_type', '=', $refType)
                ->where('ref_id', '=', $refId),
        );
    }

    /**
     * The stored dependency graph of one surface, keyed by owner subject.
     *
     * It is read from the reference table rather than by decoding every rule
     * document: "every rule that references lesson 88" is an indexed query on
     * that table and a JSON path scan without it, on MySQL, MariaDB and
     * SQLite alike.
     *
     * @return array<string, list<string>>
     */
    public function dependencyEdges(AvailabilitySurface $surface): array
    {
        $rows = $this->database->selectAll(
            Query::from('availability_rule_refs', 'r')
                ->select(['a.subject_type', 'a.subject_id', 'r.ref_type', 'r.ref_id'])
                ->join('availability_rules', 'a', 'r.owner_id', 'a.id')
                ->where('r.owner_type', '=', self::OWNER_AVAILABILITY_RULE)
                ->where('r.surface', '=', $surface->value)
                ->whereNotNull('r.ref_type'),
        );

        $edges = [];

        foreach ($rows as $row) {
            if ($row['ref_type'] === null || $row['ref_id'] === null) {
                continue;
            }

            $owner = (new GateSubject((string) $row['subject_type'], (int) $row['subject_id']))->key();
            $edges[$owner][] = (new GateSubject((string) $row['ref_type'], (int) $row['ref_id']))->key();
        }

        return $edges;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AvailabilityRule
    {
        $raw = $row['rule'] ?? null;
        $decoded = null;

        if (\is_string($raw) && $raw !== '') {
            $candidate = json_decode($raw, true);
            $decoded = \is_array($candidate) ? $candidate : null;
        }

        /** @var array<string, mixed>|null $decoded */
        $tree = $decoded === null ? new AvailabilityTree(null) : AvailabilityTreeCodec::decode($decoded);
        $updatedAt = $row['updated_at'] ?? null;

        return new AvailabilityRule(
            (int) $row['id'],
            new GateSubject((string) $row['subject_type'], (int) $row['subject_id']),
            AvailabilitySurface::from((string) ($row['surface'] ?? AvailabilitySurface::ContentGating->value)),
            $tree,
            (string) ($row['default_visibility'] ?? AvailabilityRule::VISIBILITY_SHOWN),
            $row['updated_by'] === null ? null : (int) $row['updated_by'],
            \is_string($updatedAt) && $updatedAt !== ''
                ? new \DateTimeImmutable($updatedAt, new \DateTimeZone('UTC'))
                : null,
        );
    }
}
