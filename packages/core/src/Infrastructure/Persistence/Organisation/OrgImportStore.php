<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJob;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportJobPage;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportRowProblemPage;
use Shondalai\VidyaLMS\Core\Domain\Platform\ImportRowProblemRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The organisation side of import_jobs.
 *
 * It exists because import_jobs is the third table in the schema whose
 * organisation_id is legitimately null: a migration an operator starts from
 * the site console belongs to the site, and every job both development sites
 * already carry is one. OrgScopedReader narrows unconditionally and would
 * therefore empty the operations screen, so import_jobs is on its named
 * exclusion list and the narrowing is done here instead, explicitly, against
 * a non-defaultable OrgScope.
 *
 * "Explicitly" is the whole of the difference. Every method below takes the
 * scope as its first argument and compares organisation_id to
 * $scope->organisationId with no fallback and no null branch, so an
 * organisation read cannot accidentally widen to the site: a scope is not
 * optional, and there is no code path here that runs without one.
 *
 * The row problems are reached through the job. import_row_problems carries no
 * organisation of its own, deliberately: its tenancy is the job's, so a page
 * of problems is served only after the job has been proved to belong to the
 * scope, and the alternative (a second organisation_id, denormalised onto ten
 * thousand rows and able to disagree with the job's) is a second source of
 * truth for one fact.
 */
final class OrgImportStore
{
    public function __construct(
        private readonly DatabasePort $database,
        private readonly ImportRowProblemRepository $problems,
    ) {
    }

    /**
     * This organisation's import history, newest first. Never the site's.
     */
    public function jobs(OrgScope $scope, int $page, int $perPage): ImportJobPage
    {
        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $total = $this->database->count($this->scoped($scope));

        $rows = $this->database->selectAll(
            $this->scoped($scope)
                ->orderBy('id', Query::SORT_DESC)
                ->limit($perPage)
                ->offset(($page - 1) * $perPage),
        );

        return new ImportJobPage(array_map($this->map(...), $rows), $total, $page, $perPage);
    }

    /**
     * One of this organisation's jobs, or null.
     *
     * Null covers three cases on purpose: no such job, a job belonging to
     * another organisation, and a site-scoped job. A caller who learns which
     * of the three it was learns that an id exists, and the whole point of the
     * scoped read is that they do not.
     */
    public function job(OrgScope $scope, int $jobId): ?ImportJob
    {
        if ($jobId < 1) {
            return null;
        }

        $row = $this->database->selectOne($this->scoped($scope)->where('id', '=', $jobId));

        return $row === null ? null : $this->map($row);
    }

    /**
     * One page of a job's row problems, after the job has been proved to be
     * this organisation's.
     */
    public function problems(
        OrgScope $scope,
        int $jobId,
        int $page,
        int $perPage,
        ?string $code = null,
        ?string $outcome = null,
    ): ?ImportRowProblemPage {
        if ($this->job($scope, $jobId) === null) {
            return null;
        }

        return $this->problems->page($jobId, $page, $perPage, $code, $outcome);
    }

    /**
     * The rows a job did not write, after the same proof.
     *
     * @return list<array<string, mixed>>|null
     */
    public function rejected(OrgScope $scope, int $jobId, int $limit): ?array
    {
        if ($this->job($scope, $jobId) === null) {
            return null;
        }

        return $this->problems->rejected($jobId, $limit);
    }

    /**
     * How many rows the job rejected in total, after the same proof, so a
     * download that hit its cap can say what it left behind.
     *
     * It reads the number off a one-row page rather than through a method of
     * its own, because ImportRowProblemRepository is a shipped interface: a
     * third party implementing it would stop loading the moment it grew a
     * method, and the count is already computed on every page.
     */
    public function rejectedCount(OrgScope $scope, int $jobId): ?int
    {
        if ($this->job($scope, $jobId) === null) {
            return null;
        }

        return $this->problems->page($jobId, 1, 1)->rejected;
    }

    /**
     * The tenant predicate, written once and applied to every read here.
     *
     * A private method rather than a repeated where(), because the one thing
     * that must not vary between four reads is the predicate that makes them
     * this organisation's.
     */
    private function scoped(OrgScope $scope): Query
    {
        $query = Query::from('import_jobs')->where('organisation_id', '=', $scope->organisationId);
        if ($scope->unitId !== null) {
            // The scope is stamped at intake; caller options cannot widen it.
            $query->where('organisation_unit_id', '=', $scope->unitId);
        }
        return $query;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): ImportJob
    {
        return new ImportJob(
            (int) ($row['id'] ?? 0),
            (string) ($row['importer'] ?? ''),
            (string) ($row['status'] ?? ImportJob::STATUS_PENDING),
            \is_string($row['source_path'] ?? null) ? $row['source_path'] : null,
            $this->jsonToArray($row['options'] ?? null) ?? [],
            \is_string($row['log'] ?? null) ? $row['log'] : null,
            $this->jsonToArray($row['totals'] ?? null),
            $this->dateOrNull($row['started_at'] ?? null),
            $this->dateOrNull($row['finished_at'] ?? null),
            (int) ($row['created_by'] ?? 0),
            $this->dateOrNull($row['created_at'] ?? null),
            $this->dateOrNull($row['updated_at'] ?? null),
            ($row['organisation_id'] ?? null) === null ? null : (int) $row['organisation_id'],
            organisationUnitId: ($row['organisation_unit_id'] ?? null) === null ? null : (int) $row['organisation_unit_id'],
        );
    }

    private function dateOrNull(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function jsonToArray(mixed $value): ?array
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
