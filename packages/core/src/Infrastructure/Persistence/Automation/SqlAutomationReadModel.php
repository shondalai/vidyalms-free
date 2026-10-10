<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Automation;

use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The paged reads the operator screens make, kept apart from the repositories
 * the engine writes through.
 *
 * The split is deliberate and narrow. The write-side repositories hydrate
 * domain objects because the runner needs them; a rules list, a run log and an
 * action log are pages of rows an operator reads, counted before they are
 * sliced, and hydrating five hundred domain objects to flatten them again buys
 * nothing. Nothing here writes, and nothing here is reachable from the engine.
 */
final class SqlAutomationReadModel
{
    public const RULES = SqlAutomationRuleRepository::TABLE;
    public const VERSIONS = SqlAutomationRuleRepository::VERSION_TABLE;
    public const ACTIONS = SqlAutomationRuleRepository::ACTION_TABLE;
    public const RUNS = SqlAutomationRunRepository::TABLE;
    public const ACTION_LOG = SqlAutomationActionLogRepository::TABLE;
    public const TESTS = SqlAutomationRuleTestRepository::TABLE;

    public function __construct(
        private readonly DatabasePort $database,
    ) {
    }

    /**
     * Rules in the order the engine will really resolve them: run order, then
     * creation time as the deterministic tie-break, then id.
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function rulePage(
        ?string $triggerKey,
        ?string $state,
        ?string $actionKey,
        ?string $search,
        int $limit,
        int $offset,
    ): array {
        $ruleIds = null;

        if ($actionKey !== null && $actionKey !== '') {
            $ruleIds = [];

            foreach (
                $this->database->selectAll(
                    Query::from(self::ACTIONS)->select(['rule_id'])->where('action_key', '=', $actionKey),
                ) as $row
            ) {
                $ruleIds[(int) $row['rule_id']] = true;
            }

            $ruleIds = array_keys($ruleIds);

            if ($ruleIds === []) {
                return ['items' => [], 'total' => 0];
            }
        }

        $filter = static function (Query $query) use ($triggerKey, $state, $search, $ruleIds): Query {
            if ($triggerKey !== null && $triggerKey !== '') {
                $query->where('trigger_key', '=', $triggerKey);
            }

            if ($state !== null && $state !== '') {
                $query->where('state', '=', $state);
            }

            if ($search !== null && $search !== '') {
                $query->whereContains('title', $search);
            }

            if ($ruleIds !== null) {
                $query->whereIn('id', $ruleIds);
            }

            return $query;
        };

        $rows = $this->database->selectAll(
            $filter(Query::from(self::RULES))
                ->orderBy('run_order')
                ->orderBy('created_at')
                ->orderBy('id')
                ->limit($limit)
                ->offset($offset),
        );

        return [
            'items' => array_values($rows),
            'total' => $this->database->count($filter(Query::from(self::RULES))),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function rule(int $id): ?array
    {
        return $this->database->selectOne(Query::from(self::RULES)->where('id', '=', $id));
    }

    /**
     * The step rows of one rule version, in step order.
     *
     * @return list<array<string, mixed>>
     */
    public function steps(int $ruleId, int $version): array
    {
        return array_values($this->database->selectAll(
            Query::from(self::ACTIONS)
                ->where('rule_id', '=', $ruleId)
                ->where('rule_version', '=', $version)
                ->orderBy('step_index'),
        ));
    }

    /**
     * Every action key any version of this rule names, so a screen can report
     * a registry miss without walking the version payloads.
     *
     * @return list<string>
     */
    public function actionKeys(int $ruleId): array
    {
        $keys = [];

        foreach (
            $this->database->selectAll(
                Query::from(self::ACTIONS)->select(['action_key'])->where('rule_id', '=', $ruleId),
            ) as $row
        ) {
            $keys[(string) $row['action_key']] = true;
        }

        return array_keys($keys);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function versions(int $ruleId): array
    {
        return array_values($this->database->selectAll(
            Query::from(self::VERSIONS)
                ->select(['version', 'payload_version', 'created_by', 'activated_at', 'retired_at'])
                ->where('rule_id', '=', $ruleId)
                ->orderBy('version'),
        ));
    }

    /**
     * The worst stored test result of this rule, so the list can carry a
     * failing-test badge without a scan of documents.
     */
    public function worstTestResult(int $ruleId): ?string
    {
        $rows = $this->database->selectAll(
            Query::from(self::TESTS)->select(['last_result'])->where('rule_id', '=', $ruleId),
        );
        $seen = [];

        foreach ($rows as $row) {
            if (\is_string($row['last_result'] ?? null) && $row['last_result'] !== '') {
                $seen[] = (string) $row['last_result'];
            }
        }

        foreach (['error', 'failed', 'passed'] as $worst) {
            if (\in_array($worst, $seen, true)) {
                return $worst;
            }
        }

        return null;
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function runPage(int $ruleId, ?string $state, ?bool $dryRun, int $limit, int $offset): array
    {
        $filter = static function (Query $query) use ($ruleId, $state, $dryRun): Query {
            $query->where('rule_id', '=', $ruleId);

            if ($state !== null && $state !== '') {
                $query->where('state', '=', $state);
            }

            if ($dryRun !== null) {
                $query->where('dry_run', '=', $dryRun ? 1 : 0);
            }

            return $query;
        };

        $rows = $this->database->selectAll(
            $filter(Query::from(self::RUNS))
                ->orderBy('id', Query::SORT_DESC)
                ->limit($limit)
                ->offset($offset),
        );

        return [
            'items' => array_values($rows),
            'total' => $this->database->count($filter(Query::from(self::RUNS))),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function run(int $id): ?array
    {
        return $this->database->selectOne(Query::from(self::RUNS)->where('id', '=', $id));
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function actionLogPage(int $runId, int $limit, int $offset): array
    {
        $filter = static fn (Query $query): Query => $query->where('run_id', '=', $runId);

        $rows = $this->database->selectAll(
            $filter(Query::from(self::ACTION_LOG))
                ->orderBy('step_index')
                ->orderBy('id')
                ->limit($limit)
                ->offset($offset),
        );

        return [
            'items' => array_values($rows),
            'total' => $this->database->count($filter(Query::from(self::ACTION_LOG))),
        ];
    }

    /**
     * One stored test, read back by its own id.
     *
     * @return array<string, mixed>|null
     */
    public function test(int $id): ?array
    {
        return $this->database->selectOne(Query::from(self::TESTS)->where('id', '=', $id));
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    public function testPage(int $ruleId, int $limit, int $offset): array
    {
        $filter = static fn (Query $query): Query => $query->where('rule_id', '=', $ruleId);

        $rows = $this->database->selectAll(
            $filter(Query::from(self::TESTS))->orderBy('name')->limit($limit)->offset($offset),
        );

        return [
            'items' => array_values($rows),
            'total' => $this->database->count($filter(Query::from(self::TESTS))),
        ];
    }
}
