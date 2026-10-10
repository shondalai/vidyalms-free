<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiRun;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunAttempt;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunOutcome;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunRepository;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunReservation;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunState;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunSubject;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Aggregate;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The ledger, over the DatabasePort fluent API.
 *
 * Two things here are decisions rather than plumbing.
 *
 * **reserve() writes the run and its subject rows in one call**, and the caller
 * wraps that call plus the ceiling read in one transactional(). A subject row
 * that can arrive later is a subject row that can be missing when the
 * right-to-know view asks, and a reservation written outside the ceiling's
 * transaction is a ceiling two concurrent runs both pass.
 *
 * **The windowed sum is computed in PHP over an indexed row set rather than in
 * SQL.** The Query API has no GREATEST and no expression projection, and adding
 * one to the port for this would put a MySQL function name in the core's only
 * persistence boundary. The predicate is the indexed one
 * (state, cost_unit, created_at), so the row set the sum walks is the one the
 * index already narrowed, and the narrowing is the part that has to be indexed.
 */
final class SqlAiRunRepository implements AiRunRepository
{
    private const RUNS = 'ai_runs';
    private const ATTEMPTS = 'ai_run_attempts';
    private const SUBJECTS = 'ai_run_subjects';

    /** The StoredShape version a rewritten manifest carries, matching the reserved one. */
    private const MANIFEST_VERSION = 1;
    private const DRAFTS = 'ai_drafts';
    private const FINDINGS = 'ai_draft_findings';

    /**
     * The only columns a ceiling may be scoped by.
     *
     * A scope column that came from a caller's string is an injection, and a
     * scope column that is not indexed is a full scan on the hot path of every
     * run, so the allowlist is both a safety rule and a performance one.
     *
     * @var list<string>
     */
    private const SCOPE_COLUMNS = ['course_id', 'organisation_id', 'principal_user_id', 'placement_key', 'task_key'];

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function reserve(AiRunReservation $reservation, array $subjects = []): int
    {
        $now = $this->stamp($this->clock->now());

        $runId = $this->database->insert(self::RUNS, [
            'run_key' => $reservation->runKey,
            'job_id' => $reservation->jobId,
            'task_key' => $reservation->taskKey,
            'task_version' => $reservation->taskVersion,
            'placement_key' => $reservation->placementKey,
            'route_id' => $reservation->routeId,
            'bridge_key' => $reservation->bridgeKey,
            'model' => $reservation->model,
            'prompt_ref' => null,
            'policy_version' => $reservation->policyVersion,
            'principal_user_id' => $reservation->principalUserId,
            'actor_kind' => $reservation->actorKind,
            'rule_id' => $reservation->ruleId,
            'on_behalf_of_user_id' => $reservation->onBehalfOfUserId,
            'subject_type' => $reservation->subjectType,
            'subject_id' => $reservation->subjectId,
            'course_id' => $reservation->courseId,
            'organisation_id' => $reservation->organisationId,
            'locale' => $reservation->locale,
            'inputs_digest' => $reservation->inputsDigest,
            'redaction_manifest' => $reservation->redactionManifest === null
                ? null
                : json_encode(
                    StoredShape::wrap($reservation->redactionManifest, $reservation->redactionManifestVersion),
                    JSON_THROW_ON_ERROR,
                ),
            'redaction_manifest_version' => $reservation->redactionManifestVersion,
            'dry_run' => $reservation->dryRun ? 1 : 0,
            'state' => AiRunState::Reserved->value,
            'cost_unit' => $reservation->costUnit,
            'cost_reserved' => $reservation->costReserved,
            'retain_until' => $this->stamp($reservation->retainUntil),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (AiRunSubject::distinct($subjects) as $subject) {
            $this->database->insert(self::SUBJECTS, [
                'run_id' => $runId,
                'user_id' => $subject->userId,
                'role' => $subject->role,
            ]);
        }

        return $runId;
    }

    public function markRunning(int $id, \DateTimeImmutable $at): void
    {
        $this->database->update(self::RUNS, [
            'state' => AiRunState::Running->value,
            'started_at' => $this->stamp($at),
            'updated_at' => $this->stamp($at),
        ], ['id' => $id]);
    }

    public function recordManifest(int $id, array $fields, \DateTimeImmutable $at): void
    {
        // The same StoredShape wrapper and the same version the reservation
        // wrote, so the reader cannot tell a rewritten manifest from a reserved
        // one and does not have to.
        $this->database->update(self::RUNS, [
            'redaction_manifest' => $fields === []
                ? null
                : json_encode(
                    StoredShape::wrap(['fields' => $fields], self::MANIFEST_VERSION),
                    JSON_THROW_ON_ERROR,
                ),
            'redaction_manifest_version' => self::MANIFEST_VERSION,
            'updated_at' => $this->stamp($at),
        ], ['id' => $id]);
    }

    public function recordAttempt(AiRunAttempt $attempt): void
    {
        $this->database->upsert(self::ATTEMPTS, [
            'run_id' => $attempt->runId,
            'attempt_no' => $attempt->attemptNo,
            'route_id' => $attempt->routeId,
            'position' => $attempt->position,
            'bridge_key' => $attempt->bridgeKey,
            'model' => $attempt->model,
            'success' => $attempt->success ? 1 : 0,
            'refusal_kind' => $attempt->refusalKind,
            'error_code' => $attempt->errorCode,
            'error_message' => $attempt->errorMessage,
            'provider_request_id' => $attempt->providerRequestId,
            'auto_retry_safe' => $attempt->autoRetrySafe ? 1 : 0,
            'dropped_constraints' => $attempt->droppedConstraints === []
                ? null
                : json_encode(
                    StoredShape::wrap(['keywords' => $attempt->droppedConstraints], 1),
                    JSON_THROW_ON_ERROR,
                ),
            'dropped_constraints_version' => 1,
            'tokens_in' => $attempt->tokensIn,
            'tokens_out' => $attempt->tokensOut,
            'cost_unit' => $attempt->costUnit,
            'cost_charged' => $attempt->costCharged,
            'latency_ms' => $attempt->latencyMs,
            'started_at' => $this->stamp($attempt->startedAt),
            'completed_at' => $attempt->completedAt === null ? null : $this->stamp($attempt->completedAt),
        ], ['run_id', 'attempt_no'], [
            'success',
            'refusal_kind',
            'error_code',
            'error_message',
            'provider_request_id',
            'auto_retry_safe',
            'dropped_constraints',
            'tokens_in',
            'tokens_out',
            'cost_unit',
            'cost_charged',
            'latency_ms',
            'completed_at',
        ]);
    }

    public function complete(int $id, AiRunOutcome $outcome, \DateTimeImmutable $at): void
    {
        $stamp = $this->stamp($at);

        $values = [
            'state' => $outcome->state->value,
            'refusal_kind' => $outcome->refusalKind,
            'error_code' => $outcome->errorCode,
            'error_message' => $outcome->errorMessage,
            'tokens_in' => $outcome->tokensIn,
            'tokens_out' => $outcome->tokensOut,
            'cost_unit' => $outcome->cost->unit,
            // The spend is written on every terminal path, the failed one
            // included. An import that fails costs nothing; an AI run that fails
            // has already paid the upstream tokens, and a ledger that recorded
            // nothing there leaves a site owner unable to explain their bill.
            'cost_charged' => $outcome->cost->amount,
            'unbilled' => $outcome->unbilled ? 1 : 0,
            'schema_valid' => $outcome->schemaValid === null ? null : ($outcome->schemaValid ? 1 : 0),
            'provider_request_id' => $outcome->providerRequestId,
            'provider_fingerprint' => $outcome->providerFingerprint,
            'finish_reason' => $outcome->finishReason,
            'duration_ms' => $outcome->durationMs,
            'completed_at' => $stamp,
            'settled_at' => $outcome->cost->isKnown() ? $stamp : null,
            'updated_at' => $stamp,
        ];

        // The model and the prompt reference are written only when the outcome
        // knows them. A refusal, a cancellation and a preview know neither, and
        // writing null there would erase the route's own answer from the row an
        // operator reads to find out which model was going to be asked.
        if ($outcome->model !== null) {
            $values['model'] = $outcome->model;
        }

        if ($outcome->promptRef !== null) {
            $values['prompt_ref'] = $outcome->promptRef;
        }

        $this->database->update(self::RUNS, $values, ['id' => $id]);
    }

    public function note(int $id, string $note): void
    {
        $row = $this->database->selectOne(Query::from(self::RUNS)->select(['error_message'])->where('id', '=', $id));

        if ($row === null) {
            return;
        }

        $existing = (string) ($row['error_message'] ?? '');

        $this->database->update(self::RUNS, [
            'error_message' => $existing === '' ? $note : $existing . "\n" . $note,
        ], ['id' => $id]);
    }

    public function attemptCount(int $runId): int
    {
        return $this->database->count(Query::from(self::ATTEMPTS)->where('run_id', '=', $runId));
    }

    public function find(int $id): ?AiRun
    {
        $row = $this->database->selectOne(Query::from(self::RUNS)->where('id', '=', $id));

        return $row === null ? null : $this->map($row);
    }

    public function findByKey(string $runKey): ?AiRun
    {
        $row = $this->database->selectOne(Query::from(self::RUNS)->where('run_key', '=', $runKey));

        return $row === null ? null : $this->map($row);
    }

    public function forJob(int $jobId): array
    {
        return array_map(
            $this->map(...),
            $this->database->selectAll(
                Query::from(self::RUNS)->where('job_id', '=', $jobId)->orderBy('id', Query::SORT_ASC),
            ),
        );
    }

    public function spentInWindow(
        string $costUnit,
        \DateTimeImmutable $since,
        ?string $scopeColumn = null,
        ?int $scopeId = null,
    ): float {
        // Two bounded aggregates rather than a fold over every billable row in
        // the window. This is read once per run, so hydrating a hundred
        // thousand rows on every click of every AI helper was the cost of a
        // GREATEST the fluent API had no expression for.
        //
        // The split is not an approximation of that GREATEST: it is what the
        // GREATEST was standing in for. A run that has not settled is bound by
        // the headroom it is holding, and a run that has settled is bound by
        // what it actually charged, because the spend is written on every
        // terminal path.
        return $this->windowedSum('cost_reserved', AiRunState::holdingStates(), $costUnit, $since, $scopeColumn, $scopeId)
            + $this->windowedSum('cost_charged', AiRunState::settledStates(), $costUnit, $since, $scopeColumn, $scopeId);
    }

    /**
     * One SUM over one slice of the window.
     *
     * @param list<string> $states
     */
    private function windowedSum(
        string $column,
        array $states,
        string $costUnit,
        \DateTimeImmutable $since,
        ?string $scopeColumn,
        ?int $scopeId,
    ): float {
        if ($states === []) {
            return 0.0;
        }

        $query = Query::from(self::RUNS)
            ->select([])
            ->aggregate(Aggregate::sum($column, 'total'))
            ->whereIn('state', $states)
            ->where('cost_unit', '=', $costUnit)
            ->where('created_at', '>=', $this->stamp($since));

        $this->narrow($query, $scopeColumn, $scopeId);

        $row = $this->database->selectOne($query);

        return (float) ($row['total'] ?? 0.0);
    }

    public function countInWindow(
        \DateTimeImmutable $since,
        array $states,
        ?string $scopeColumn = null,
        ?int $scopeId = null,
    ): int {
        $query = Query::from(self::RUNS)
            ->whereIn('state', $states === [] ? AiRunState::billableStates() : $states)
            ->where('created_at', '>=', $this->stamp($since));

        $this->narrow($query, $scopeColumn, $scopeId);

        return $this->database->count($query);
    }

    public function expiredBefore(\DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->ids(
            Query::from(self::RUNS)
                ->select(['id'])
                ->where('retain_until', '<', $this->stamp($cutoff))
                ->orderBy('id', Query::SORT_ASC)
                ->limit(max(1, $limit)),
        );
    }

    public function purge(array $runIds): int
    {
        if ($runIds === []) {
            return 0;
        }

        $draftIds = $this->ids(Query::from(self::DRAFTS)->select(['id'])->whereIn('run_id', $runIds));

        foreach ($draftIds as $draftId) {
            $this->database->delete(self::FINDINGS, ['draft_id' => $draftId]);
        }

        $purged = 0;

        foreach ($runIds as $runId) {
            $this->database->delete(self::DRAFTS, ['run_id' => $runId]);
            $this->database->delete(self::ATTEMPTS, ['run_id' => $runId]);
            $this->database->delete(self::SUBJECTS, ['run_id' => $runId]);
            $purged += $this->database->delete(self::RUNS, ['id' => $runId]);
        }

        // ai_artefact_provenance is not touched here, and its absence from this
        // method is the mechanism rather than a comment about one: the record
        // that earns the Article 50(4) exemption has to outlive the prompt log
        // that produced it.
        return $purged;
    }

    public function abandonedBefore(\DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->ids(
            Query::from(self::RUNS)
                ->select(['id'])
                ->whereIn('state', [AiRunState::Reserved->value, AiRunState::Running->value])
                ->where('created_at', '<', $this->stamp($cutoff))
                ->orderBy('id', Query::SORT_ASC)
                ->limit(max(1, $limit)),
        );
    }

    public function releaseReservation(int $id, \DateTimeImmutable $at): void
    {
        $stamp = $this->stamp($at);

        $this->database->update(self::RUNS, [
            'state' => AiRunState::Failed->value,
            'error_code' => 'reservation_released',
            'error_message' => 'The run never reached a terminal state and its reservation was released by the'
                . ' retention sweep, so the headroom it held is available again.',
            // The reservation is cleared rather than kept, because the whole
            // point of releasing it is that it stops occupying the window.
            'cost_reserved' => null,
            'completed_at' => $stamp,
            'updated_at' => $stamp,
        ], ['id' => $id]);
    }

    private function narrow(Query $query, ?string $scopeColumn, ?int $scopeId): void
    {
        if ($scopeColumn === null || $scopeId === null) {
            return;
        }

        if (!\in_array($scopeColumn, self::SCOPE_COLUMNS, true)) {
            throw new \LogicException(sprintf(
                'A ceiling may be scoped only by %s, and "%s" is not one of them.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                implode(', ', self::SCOPE_COLUMNS),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $scopeColumn,
            ));
        }

        $query->where($scopeColumn, '=', $scopeId);
    }

    /**
     * @return list<int>
     */
    private function ids(Query $query): array
    {
        return array_values(array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->database->selectAll($query),
        ));
    }

    private function stamp(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): AiRun
    {
        $manifest = $this->document($row['redaction_manifest'] ?? null);

        return new AiRun(
            (int) $row['id'],
            (string) $row['run_key'],
            $this->nullableInt($row['job_id'] ?? null),
            (string) $row['task_key'],
            (int) ($row['task_version'] ?? 1),
            $this->nullableString($row['placement_key'] ?? null),
            $this->nullableInt($row['route_id'] ?? null),
            (string) ($row['bridge_key'] ?? 'null'),
            $this->nullableString($row['model'] ?? null),
            $this->nullableString($row['prompt_ref'] ?? null),
            (int) ($row['policy_version'] ?? 0),
            $this->nullableInt($row['principal_user_id'] ?? null),
            (string) ($row['actor_kind'] ?? AiRunReservation::ACTOR_PERSON),
            $this->nullableInt($row['rule_id'] ?? null),
            $this->nullableInt($row['on_behalf_of_user_id'] ?? null),
            $this->nullableString($row['subject_type'] ?? null),
            $this->nullableInt($row['subject_id'] ?? null),
            $this->nullableInt($row['course_id'] ?? null),
            $this->nullableInt($row['organisation_id'] ?? null),
            (string) ($row['locale'] ?? 'en-GB'),
            (string) ($row['inputs_digest'] ?? ''),
            $manifest,
            (int) ($row['redaction_manifest_version'] ?? 1),
            (bool) ($row['dry_run'] ?? false),
            AiRunState::from((string) ($row['state'] ?? AiRunState::Reserved->value)),
            $this->nullableString($row['refusal_kind'] ?? null),
            $this->nullableString($row['error_code'] ?? null),
            $this->nullableString($row['error_message'] ?? null),
            $this->nullableInt($row['tokens_in'] ?? null),
            $this->nullableInt($row['tokens_out'] ?? null),
            (string) ($row['cost_unit'] ?? 'none'),
            $this->nullableFloat($row['cost_reserved'] ?? null),
            $this->nullableFloat($row['cost_charged'] ?? null),
            (bool) ($row['unbilled'] ?? false),
            isset($row['schema_valid']) ? (bool) $row['schema_valid'] : null,
            $this->nullableString($row['provider_request_id'] ?? null),
            $this->nullableString($row['provider_fingerprint'] ?? null),
            $this->nullableString($row['finish_reason'] ?? null),
            $this->nullableInt($row['duration_ms'] ?? null),
            $this->moment($row['started_at'] ?? null),
            $this->moment($row['completed_at'] ?? null),
            $this->moment($row['settled_at'] ?? null),
            $this->moment($row['retain_until'] ?? null) ?? new \DateTimeImmutable('@0'),
            $this->moment($row['created_at'] ?? null),
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

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function moment(mixed $value): ?\DateTimeImmutable
    {
        if (!\is_string($value) || $value === '') {
            return null;
        }

        return new \DateTimeImmutable($value);
    }
}
