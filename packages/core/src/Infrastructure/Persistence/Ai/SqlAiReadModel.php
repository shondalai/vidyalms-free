<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftState;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * The paged reads the AI screens make, kept apart from the writers the
 * pipeline uses.
 *
 * The split follows the automation precedent exactly and for the same reason:
 * the run pipeline needs objects, while a run log, a review queue and the
 * learner's own activity list are pages of rows counted before they are
 * sliced, and hydrating five hundred objects to flatten them again buys
 * nothing.
 *
 * Nothing here writes. Every stored JSON column is unwrapped through
 * StoredShape so the reserved version key never reaches a response body: the
 * version travels in its own mirrored column, which is what makes a batch
 * upgrade an index scan rather than a JSON path query.
 */
final class SqlAiReadModel
{
    public const ROUTES = 'ai_routes';
    public const POLICIES = 'ai_policies';
    public const ACCEPTANCES = 'ai_policy_acceptances';
    public const RUNS = 'ai_runs';
    public const ATTEMPTS = 'ai_run_attempts';
    public const SUBJECTS = 'ai_run_subjects';
    public const DRAFTS = 'ai_drafts';
    public const FINDINGS = 'ai_draft_findings';
    public const PROVENANCE = 'ai_artefact_provenance';
    public const JOBS = 'ai_jobs';

    public function __construct(
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * The active policy version, or null when a site owner has activated
     * none. Newest effective row wins, so activating tomorrow's version today
     * does not take effect until tomorrow.
     *
     * @return array<string, mixed>|null
     */
    public function activePolicy(string $now): ?array
    {
        return $this->database->selectOne(
            Query::from(self::POLICIES)
                ->where('state', '=', 'active')
                ->where('effective_from', '<=', $now)
                ->orderBy('effective_from', Query::SORT_DESC)
                ->orderBy('version', Query::SORT_DESC)
                ->limit(1),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function policy(int $version): ?array
    {
        return $this->database->selectOne(Query::from(self::POLICIES)->where('version', '=', $version));
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function policyPage(?string $state, int $limit, int $offset): array
    {
        $filter = static function (Query $query) use ($state): Query {
            if ($state !== null && $state !== '') {
                $query->where('state', '=', $state);
            }

            return $query;
        };

        return [
            'items' => array_values($this->database->selectAll(
                $filter(Query::from(self::POLICIES))->orderBy('version', Query::SORT_DESC)->limit($limit)->offset($offset),
            )),
            'total' => $this->database->count($filter(Query::from(self::POLICIES))),
        ];
    }

    public function acceptanceCount(int $policyVersion): int
    {
        // The unique user/version/scope key makes this one row per person.
        return $this->database->count(Query::from(self::ACCEPTANCES)->where('policy_version', '=', $policyVersion)
            ->where('scope_type', '=', 'site')->where('scope_id', '=', 0));
    }

    /**
     * This person's acceptance of any version, newest first. The active
     * version is compared against it by the caller rather than filtered here,
     * because "accepted version 3 while 4 is active" is the answer that
     * re-prompts and it needs the older row to say so.
     *
     * @return array<string, mixed>|null
     */
    public function latestAcceptance(int $userId, string $scopeType = 'site', int $scopeId = 0): ?array
    {
        return $this->database->selectOne(
            Query::from(self::ACCEPTANCES)
                ->where('user_id', '=', $userId)
                ->where('scope_type', '=', $scopeType)
                ->where('scope_id', '=', $scopeId)
                ->orderBy('policy_version', Query::SORT_DESC)
                ->limit(1),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function acceptance(int $userId, int $policyVersion, string $scopeType, int $scopeId): ?array
    {
        return $this->database->selectOne(
            Query::from(self::ACCEPTANCES)
                ->where('user_id', '=', $userId)
                ->where('policy_version', '=', $policyVersion)
                ->where('scope_type', '=', $scopeType)
                ->where('scope_id', '=', $scopeId),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function route(int $id): ?array
    {
        return $this->database->selectOne(Query::from(self::ROUTES)->where('id', '=', $id));
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function routePage(?string $taskKey, ?string $bridgeKey, ?bool $enabled, int $limit, int $offset): array
    {
        $filter = static function (Query $query) use ($taskKey, $bridgeKey, $enabled): Query {
            if ($taskKey !== null && $taskKey !== '') {
                $query->where('task_key', '=', $taskKey);
            }

            if ($bridgeKey !== null && $bridgeKey !== '') {
                $query->where('bridge_key', '=', $bridgeKey);
            }

            if ($enabled !== null) {
                $query->where('enabled', '=', $enabled ? 1 : 0);
            }

            return $query;
        };

        return [
            'items' => array_values($this->database->selectAll(
                $filter(Query::from(self::ROUTES))
                    ->orderBy('task_key')
                    ->orderBy('position')
                    ->orderBy('id')
                    ->limit($limit)
                    ->offset($offset),
            )),
            'total' => $this->database->count($filter(Query::from(self::ROUTES))),
        ];
    }

    /**
     * How many distinct task keys have at least one enabled route. It is the
     * third of the four enablement levels expressed as one number, which is
     * what the admin screen renders beside the site switch.
     *
     * @return array{total: int, enabled: int, tasks_with_enabled_route: int}
     */
    public function routeSummary(): array
    {
        $total = $this->database->count(Query::from(self::ROUTES));
        $enabled = 0;
        $tasks = [];

        foreach ($this->database->selectAll(Query::from(self::ROUTES)->select(['task_key', 'enabled'])) as $row) {
            if ((int) ($row['enabled'] ?? 0) === 1) {
                $enabled++;
                $tasks[(string) ($row['task_key'] ?? '')] = true;
            }
        }

        unset($tasks['']);

        return ['total' => $total, 'enabled' => $enabled, 'tasks_with_enabled_route' => \count($tasks)];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function run(int $id): ?array
    {
        return $this->database->selectOne(Query::from(self::RUNS)->where('id', '=', $id));
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function runPage(array $filters, int $limit, int $offset): array
    {
        $filter = static function (Query $query) use ($filters): Query {
            foreach (['state', 'task_key', 'placement_key'] as $column) {
                $value = $filters[$column] ?? null;

                if (\is_string($value) && $value !== '') {
                    $query->where($column, '=', $value);
                }
            }

            foreach (['principal_user_id', 'course_id', 'organisation_id', 'job_id'] as $column) {
                $value = $filters[$column] ?? null;

                if (\is_int($value) && $value > 0) {
                    $query->where($column, '=', $value);
                }
            }

            if (isset($filters['dry_run']) && \is_bool($filters['dry_run'])) {
                $query->where('dry_run', '=', $filters['dry_run'] ? 1 : 0);
            }

            return $query;
        };

        return [
            'items' => array_values($this->database->selectAll(
                $filter(Query::from(self::RUNS))
                    ->orderBy('id', Query::SORT_DESC)
                    ->limit($limit)
                    ->offset($offset),
            )),
            'total' => $this->database->count($filter(Query::from(self::RUNS))),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function attempts(int $runId): array
    {
        return array_values($this->database->selectAll(
            Query::from(self::ATTEMPTS)->where('run_id', '=', $runId)->orderBy('attempt_no'),
        ));
    }

    public function subjectCount(int $runId): int
    {
        return $this->database->count(Query::from(self::SUBJECTS)->where('run_id', '=', $runId));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function draft(int $id): ?array
    {
        return $this->database->selectOne(Query::from(self::DRAFTS)->where('id', '=', $id));
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function draftPage(array $filters, int $limit, int $offset): array
    {
        $runIds = null;

        if (isset($filters['job_id']) && \is_int($filters['job_id']) && $filters['job_id'] > 0) {
            $runIds = [];

            foreach (
                $this->database->selectAll(
                    Query::from(self::RUNS)->select(['id'])->where('job_id', '=', $filters['job_id']),
                ) as $row
            ) {
                $runIds[] = (int) $row['id'];
            }

            if ($runIds === []) {
                return ['items' => [], 'total' => 0];
            }
        }

        $filter = static function (Query $query) use ($filters, $runIds): Query {
            foreach (['state', 'draft_kind'] as $column) {
                $value = $filters[$column] ?? null;

                if (\is_string($value) && $value !== '') {
                    $query->where($column, '=', $value);
                }
            }

            foreach (['course_id', 'run_id'] as $column) {
                $value = $filters[$column] ?? null;

                if (\is_int($value) && $value > 0) {
                    $query->where($column, '=', $value);
                }
            }

            if ($runIds !== null) {
                $query->whereIn('run_id', $runIds);
            }

            return $query;
        };

        return [
            'items' => array_values($this->database->selectAll(
                $filter(Query::from(self::DRAFTS))
                    ->orderBy('findings_blocking', Query::SORT_DESC)
                    ->orderBy('id', Query::SORT_DESC)
                    ->limit($limit)
                    ->offset($offset),
            )),
            'total' => $this->database->count($filter(Query::from(self::DRAFTS))),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findings(int $draftId): array
    {
        return array_values($this->database->selectAll(
            Query::from(self::FINDINGS)->where('draft_id', '=', $draftId)->orderBy('severity')->orderBy('id'),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function draftsOfRun(int $runId): array
    {
        return array_values($this->database->selectAll(
            Query::from(self::DRAFTS)->where('run_id', '=', $runId)->orderBy('ordinal')->orderBy('id'),
        ));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function job(int $id): ?array
    {
        return $this->database->selectOne(Query::from(self::JOBS)->where('id', '=', $id));
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function jobPage(array $filters, int $limit, int $offset): array
    {
        $filter = static function (Query $query) use ($filters): Query {
            foreach (['status', 'scope_type'] as $column) {
                $value = $filters[$column] ?? null;

                if (\is_string($value) && $value !== '') {
                    $query->where($column, '=', $value);
                }
            }

            foreach (['scope_id', 'created_by'] as $column) {
                $value = $filters[$column] ?? null;

                if (\is_int($value) && $value > 0) {
                    $query->where($column, '=', $value);
                }
            }

            return $query;
        };

        return [
            'items' => array_values($this->database->selectAll(
                $filter(Query::from(self::JOBS))->orderBy('id', Query::SORT_DESC)->limit($limit)->offset($offset),
            )),
            'total' => $this->database->count($filter(Query::from(self::JOBS))),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function provenanceFor(string $subjectType, int $subjectId): array
    {
        return array_values($this->database->selectAll(
            Query::from(self::PROVENANCE)
                ->where('subject_type', '=', $subjectType)
                ->where('subject_id', '=', $subjectId)
                ->orderBy('accepted_at', Query::SORT_DESC)
                ->orderBy('run_id', Query::SORT_DESC),
        ));
    }

    /**
     * The learner's own rows, read from ai_run_subjects rather than from a
     * single column on the run: one nullable integer cannot record the
     * subjects of a cohort narrative or a bulk feedback job, which are exactly
     * the runs that touch the most learners.
     *
     * @return array{items: list<array{run: array<string, mixed>, role: string}>, total: int}
     */
    public function activityPage(int $userId, ?string $from, int $limit, int $offset): array
    {
        $subjectRows = $this->database->selectAll(
            Query::from(self::SUBJECTS)->select(['run_id', 'role'])->where('user_id', '=', $userId),
        );

        $roles = [];

        foreach ($subjectRows as $row) {
            $roles[(int) $row['run_id']] = (string) ($row['role'] ?? 'subject');
        }

        if ($roles === []) {
            return ['items' => [], 'total' => 0];
        }

        $runIds = array_keys($roles);

        $filter = static function (Query $query) use ($runIds, $from): Query {
            $query->whereIn('id', $runIds);

            if ($from !== null && $from !== '') {
                $query->where('created_at', '>=', $from);
            }

            return $query;
        };

        $items = [];

        foreach (
            $this->database->selectAll(
                $filter(Query::from(self::RUNS))->orderBy('id', Query::SORT_DESC)->limit($limit)->offset($offset),
            ) as $run
        ) {
            $items[] = [
                'run' => $run,
                'role' => $roles[(int) $run['id']] ?? 'subject',
                // What became of what the run produced. The design's own list
                // of what a learner is shown ends with "whether the output was
                // accepted or discarded", and the view carried the RUN state
                // instead, which answers a different question: a succeeded run
                // whose every draft a reviewer threw away read as succeeded.
                'outcome' => $this->draftOutcomeOf((int) $run['id']),
            ];
        }

        return ['items' => $items, 'total' => $this->database->count($filter(Query::from(self::RUNS)))];
    }

    /**
     * One word for what happened to a run's drafts.
     *
     * `accepted` when any draft became an artefact, because one accepted
     * output is the fact a subject needs to know; `discarded` when every one
     * was discarded, which is where a rejected draft ends up once a person
     * has finished with it; `pending` while a person has still not decided;
     * and `none` for a run that produced no draft at all, which is every
     * check and every refusal.
     */
    private function draftOutcomeOf(int $runId): string
    {
        $states = [];

        foreach (
            $this->database->selectAll(
                Query::from(self::DRAFTS)->select(['state'])->where('run_id', '=', $runId),
            ) as $row
        ) {
            $states[] = (string) ($row['state'] ?? '');
        }

        if ($states === []) {
            return 'none';
        }

        if (\in_array(AiDraftState::Accepted->value, $states, true)) {
            return 'accepted';
        }

        foreach ($states as $state) {
            if ($state !== AiDraftState::Discarded->value) {
                return 'pending';
            }
        }

        return 'discarded';
    }

    /**
     * Every run in a window, for the usage report. It reads the rows rather
     * than a second counter table, because the ceiling window is already an
     * indexed query over exactly these columns.
     *
     * @return list<array<string, mixed>>
     */
    public function runsInWindow(?string $from, ?string $to, ?int $organisationId): array
    {
        $query = Query::from(self::RUNS);

        if ($from !== null && $from !== '') {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->where('created_at', '<=', $to);
        }

        if ($organisationId !== null && $organisationId > 0) {
            $query->where('organisation_id', '=', $organisationId);
        }

        return array_values($this->database->selectAll($query));
    }

    /**
     * Accepted drafts in a window, for the oversight measurement. The edit
     * distance ratio is read from the draft rather than the provenance row
     * because both carry it and the draft is the one the queue sorts on.
     *
     * @return list<array<string, mixed>>
     */
    public function acceptedDrafts(?string $from, ?string $to): array
    {
        $query = Query::from(self::DRAFTS)->where('state', '=', 'accepted');

        if ($from !== null && $from !== '') {
            $query->where('accepted_at', '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->where('accepted_at', '<=', $to);
        }

        return array_values($this->database->selectAll($query));
    }

    /**
     * A stored JSON column as its body, with the reserved version key
     * stripped. Null and the empty string both mean "no document", which is a
     * different fact from an empty one and is why this answers null.
     *
     * @return array<string, mixed>|null
     */
    public static function shape(mixed $value): ?array
    {
        $decoded = self::decode($value);

        return $decoded === null ? null : StoredShape::bodyOf($decoded);
    }

    /**
     * @return list<mixed>|null
     */
    public static function shapeList(mixed $value): ?array
    {
        $decoded = self::decode($value);

        if ($decoded === null) {
            return null;
        }

        $body = StoredShape::bodyOf($decoded);

        return array_is_list($body) ? $body : array_values($body);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function decode(mixed $value): ?array
    {
        if (\is_array($value)) {
            return $value;
        }

        if (!\is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);

        return \is_array($decoded) ? $decoded : null;
    }
}
