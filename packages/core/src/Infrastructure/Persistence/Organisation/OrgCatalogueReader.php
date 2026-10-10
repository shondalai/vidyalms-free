<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\OrgCatalogueSource\OrgCatalogueReaderInterface;

/**
 * The three reads a catalogue source may make, narrowed at construction.
 *
 * One of these is built per resolution, holding the scope that authorised it,
 * so the tenant is fixed before any provider sees the object and no argument a
 * provider passes can move it. That is what lets
 * {@see \Shondalai\VidyaLMS\Core\Registry\Context\OrgCatalogueContext} carry a
 * reader at all: the alternative was a `DatabasePort` on the context every org
 * provider receives, which the design refuses in writing.
 *
 * The two organisation reads start at {@see OrgScopedReader}. The third reads
 * `courses`, which is a site table with no tenant, and is bounded by the ids the
 * caller already holds rather than by a predicate.
 */
final class OrgCatalogueReader implements OrgCatalogueReaderInterface
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly OrgScope $scope,
    ) {
    }

    /**
     * @return list<int>
     */
    public function releasedCourseIds(): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $query = OrgScopedReader::from($this->scope, 'org_course_releases')
            ->select(['course_id'])
            ->where('state', '=', 'active')
            ->whereAnyOf(Predicate::isNull('starts_at'), Predicate::compare('starts_at', '<=', $now))
            ->whereAnyOf(Predicate::isNull('ends_at'), Predicate::compare('ends_at', '>', $now));

        return $this->ids($this->database->selectAll($query), 'course_id');
    }

    /**
     * @param list<int>    $unitIds
     * @param list<string> $excludeSourceKeys
     *
     * @return list<array{course_id: int, unit_id: int}>
     */
    public function courseIdsAssignedToUnits(array $unitIds, array $excludeSourceKeys = []): array
    {
        if ($unitIds === []) {
            return [];
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $query = OrgScopedReader::from($this->scope, 'org_catalogue_entries')
            ->select(['course_id', 'scope_id', 'source_key'])
            ->where('scope_type', '=', 'unit')
            ->where('state', '=', 'active')
            ->whereIn('scope_id', $unitIds)
            ->whereNotNull('course_id')
            ->whereAnyOf(Predicate::isNull('starts_at'), Predicate::compare('starts_at', '<=', $now))
            ->whereAnyOf(Predicate::isNull('ends_at'), Predicate::compare('ends_at', '>', $now));

        $rows = [];

        // The exclusion is applied after the read rather than as a predicate,
        // because the fluent API has no negated set comparison and inventing
        // one on DatabasePort for a bounded filter would be a port change owed
        // to a convenience. The read is already narrowed to one organisation's
        // active unit entries, so the set it filters is small by construction.
        foreach ($this->database->selectAll($query) as $row) {
            if (!isset($row['course_id'])) {
                continue;
            }

            if (\in_array((string) ($row['source_key'] ?? ''), $excludeSourceKeys, true)) {
                continue;
            }

            $rows[] = ['course_id' => (int) $row['course_id'], 'unit_id' => (int) ($row['scope_id'] ?? 0)];
        }

        return $rows;
    }

    /**
     * @param list<int> $courseIds
     *
     * @return list<int>
     */
    public function publishedCourseIds(array $courseIds): array
    {
        if ($courseIds === []) {
            return [];
        }

        $rows = $this->database->selectAll(
            Query::from('courses')
                ->select(['id'])
                ->whereIn('id', $courseIds)
                ->where('state', '=', 'published'),
        );

        return $this->ids($rows, 'id');
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<int>
     */
    private function ids(array $rows, string $column): array
    {
        $ids = [];

        foreach ($rows as $row) {
            $value = $row[$column] ?? null;

            if ($value !== null) {
                $ids[(int) $value] = true;
            }
        }

        return array_map(static fn (int|string $id): int => (int) $id, array_keys($ids));
    }
}
