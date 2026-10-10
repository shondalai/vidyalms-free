<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiJob;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJobRepository;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiJobStatus;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * AI jobs, over the DatabasePort fluent API.
 *
 * The guarded writes are the import pipeline's, verbatim: finishIfRunning() and
 * deferIfRunning() both match only a row still marked running, so an operator
 * who ended the job between a read and the write cannot be overwritten, and the
 * operator's decision is final by construction rather than by timing.
 *
 * recordProgress() and touch() never touch status or finished_at, which is what
 * makes them the two writes a late-finishing run may still make.
 */
final class SqlAiJobRepository implements AiJobRepository
{
    private const JOBS = 'ai_jobs';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function create(
        ?string $taskKey,
        ?string $recipeKey,
        string $scopeType,
        int $scopeId,
        array $options,
        int $createdBy,
        bool $dryRun = false,
        ?string $placementKey = null,
        ?int $organisationId = null,
    ): int {
        // Exactly one, refused here rather than discovered at drain time. A row
        // naming neither is unrunnable and a row naming both is ambiguous, and
        // both are cheap to refuse at the only place they can be written.
        if (($taskKey === null) === ($recipeKey === null)) {
            throw ValidationException::forField(
                'task_key',
                'An AI job names exactly one of a task key and a recipe key.',
            );
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');

        return $this->database->insert(self::JOBS, [
            'task_key' => $taskKey,
            'recipe_key' => $recipeKey,
            'step_index' => 0,
            'placement_key' => $placementKey,
            'status' => AiJobStatus::Pending->value,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            // Stamped from the run context, which is an authorised OrgScope or
            // nothing at all. There is deliberately no branch here that reads an
            // organisation out of the options map, because the options map is
            // where a caller's own payload arrives.
            'organisation_id' => $organisationId,
            'options' => $options === [] ? null : json_encode(StoredShape::wrap($options, 1), JSON_THROW_ON_ERROR),
            'options_version' => 1,
            'dry_run' => $dryRun ? 1 : 0,
            'created_by' => $createdBy,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function markRunning(int $id): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->database->update(self::JOBS, [
            'status' => AiJobStatus::Running->value,
            'started_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id]);
    }

    public function recordProgress(int $id, string $log, array $totals): void
    {
        $this->database->update(self::JOBS, [
            'log' => $log,
            'totals' => $totals === [] ? null : json_encode(StoredShape::wrap($totals, 1), JSON_THROW_ON_ERROR),
            'totals_version' => 1,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function touch(int $id): void
    {
        $this->database->update(self::JOBS, [
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function recordSpend(int $id, string $costUnit, ?float $reserved, ?float $charged): void
    {
        $this->database->update(self::JOBS, [
            'cost_unit' => $costUnit,
            'cost_reserved' => $reserved,
            'cost_charged' => $charged,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function finishIfRunning(int $id, AiJobStatus $status, string $log, array $totals): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        return $this->database->update(self::JOBS, [
            'status' => $status->value,
            'log' => $log,
            'totals' => $totals === [] ? null : json_encode(StoredShape::wrap($totals, 1), JSON_THROW_ON_ERROR),
            'totals_version' => 1,
            'finished_at' => $now,
            'updated_at' => $now,
        ], ['id' => $id, 'status' => AiJobStatus::Running->value]) > 0;
    }

    public function deferIfRunning(int $id, \DateTimeImmutable $resumeAt, string $log, array $totals): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        return $this->database->update(self::JOBS, [
            'status' => AiJobStatus::Deferred->value,
            'log' => $log,
            'totals' => $totals === [] ? null : json_encode(StoredShape::wrap($totals, 1), JSON_THROW_ON_ERROR),
            'totals_version' => 1,
            'resume_at' => $resumeAt->format('Y-m-d H:i:s'),
            // finished_at is deliberately NOT written. A deferred job is not
            // finished: it is parked, and the resume is the same row under the
            // same dedupe key rather than a second job.
            'updated_at' => $now,
        ], ['id' => $id, 'status' => AiJobStatus::Running->value]) > 0;
    }

    public function reopenDeferred(int $id): bool
    {
        return $this->database->update(self::JOBS, [
            'status' => AiJobStatus::Pending->value,
            'resume_at' => null,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ], ['id' => $id, 'status' => AiJobStatus::Deferred->value]) > 0;
    }

    public function find(int $id): ?AiJob
    {
        $row = $this->database->selectOne(Query::from(self::JOBS)->where('id', '=', $id));

        return $row === null ? null : $this->map($row);
    }

    public function dueForResume(\DateTimeImmutable $now, int $limit): array
    {
        return array_map($this->map(...), $this->database->selectAll(
            Query::from(self::JOBS)
                ->where('status', '=', AiJobStatus::Deferred->value)
                ->where('resume_at', '<=', $now->format('Y-m-d H:i:s'))
                ->orderBy('resume_at', Query::SORT_ASC)
                ->limit(max(1, $limit)),
        ));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): AiJob
    {
        return new AiJob(
            (int) $row['id'],
            isset($row['task_key']) ? (string) $row['task_key'] : null,
            isset($row['recipe_key']) ? (string) $row['recipe_key'] : null,
            (int) ($row['step_index'] ?? 0),
            isset($row['placement_key']) ? (string) $row['placement_key'] : null,
            AiJobStatus::from((string) ($row['status'] ?? AiJobStatus::Pending->value)),
            (string) ($row['scope_type'] ?? 'course'),
            (int) ($row['scope_id'] ?? 0),
            isset($row['organisation_id']) ? (int) $row['organisation_id'] : null,
            $this->document($row['options'] ?? null) ?? [],
            (int) ($row['options_version'] ?? 1),
            (bool) ($row['dry_run'] ?? false),
            (string) ($row['cost_unit'] ?? 'none'),
            isset($row['cost_reserved']) ? (float) $row['cost_reserved'] : null,
            isset($row['cost_charged']) ? (float) $row['cost_charged'] : null,
            isset($row['log']) ? (string) $row['log'] : null,
            $this->document($row['totals'] ?? null),
            $this->moment($row['resume_at'] ?? null),
            $this->moment($row['started_at'] ?? null),
            $this->moment($row['finished_at'] ?? null),
            (int) ($row['created_by'] ?? 0),
            $this->moment($row['created_at'] ?? null),
            $this->moment($row['updated_at'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function document(mixed $value): ?array
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, true);

        if (!\is_array($decoded)) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return StoredShape::bodyOf($decoded);
    }

    private function moment(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return new \DateTimeImmutable($value);
    }
}
