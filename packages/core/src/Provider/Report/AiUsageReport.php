<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Provider\Report;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiPlacementInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Registries;
use Shondalai\VidyaLMS\Core\Registry\Report\ReportProviderInterface;

/**
 * Where the money went, and how much of it was reviewed.
 *
 * It is a registered report rather than a hand written screen query, so it
 * declares its own required capability and a site wanting a different cut
 * registers a report instead of editing a route. `GET /v1/manage/ai/usage`
 * resolves it through the registry, which is what the design means by the
 * usage report having its own capability and its own screen.
 *
 * Two rules make the numbers honest.
 *
 * **Spend is never summed across cost units.** A bridge metering in its
 * provider's own unit and one metering in tokens are not addable, so every
 * grouping carries the unit it counted in and a site running two units sees two
 * rows rather than one wrong one. A group whose bridges reported nothing
 * carries a null amount and not a zero, because a report saying a site spent
 * nothing is worse than one saying it does not know.
 *
 * **The oversight rows are the point, not a footnote.** The accept-unedited
 * rate and the median edit distance per task are what tell a site owner whether
 * a task sitting at 99 percent accepted is excellent or unreviewed. An Accept
 * button is not a control; a measured accept-unedited rate is the closest thing
 * to one that a report can carry.
 */
final class AiUsageReport implements ReportProviderInterface
{
    public const KEY = 'ai.usage';

    /** The states a run is counted in. A previewed run cost nothing and says so. */
    private const SPENDING_STATES = ['reserved', 'running', 'succeeded', 'failed'];

    public function __construct(
        private readonly SqlAiReadModel $reads,
        private readonly Registries $registries,
        private readonly TranslatorPort $translator,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return $this->translator->translate('vidyalms.report.ai_usage');
    }

    public function requiredCapability(): string
    {
        return Capability::MANAGE;
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(ReportContext $context): array
    {
        $from = $context->from?->format('Y-m-d H:i:s');
        $until = $context->until?->format('Y-m-d H:i:s');
        $organisationId = self::intOf($context->parameters, 'organisation_id');

        $runs = $this->reads->runsInWindow($from, $until, $organisationId);

        $totals = [];
        $byTask = [];
        $byPlacement = [];
        $byPrincipal = [];
        $byCourse = [];
        $byOrganisation = [];
        $refusals = [];
        $metered = false;

        foreach ($runs as $run) {
            $unit = (string) ($run['cost_unit'] ?? 'none');
            $charged = $run['cost_charged'] ?? null;
            $counted = \in_array((string) ($run['state'] ?? ''), self::SPENDING_STATES, true);
            $amount = $counted && is_numeric($charged) ? (int) $charged : null;
            $metered = $metered || $amount !== null;
            $refused = (string) ($run['state'] ?? '') === 'refused';

            self::add($totals, 'all', 'All runs', $unit, $amount, $refused);
            self::add(
                $byTask,
                (string) ($run['task_key'] ?? ''),
                $this->taskLabel((string) ($run['task_key'] ?? '')),
                $unit,
                $amount,
                $refused,
            );

            $placementKey = $run['placement_key'] ?? null;

            if (\is_string($placementKey) && $placementKey !== '') {
                self::add($byPlacement, $placementKey, $this->placementLabel($placementKey), $unit, $amount, $refused);
            }

            self::addNumeric($byPrincipal, $run['principal_user_id'] ?? null, 'user', $unit, $amount, $refused);
            self::addNumeric($byCourse, $run['course_id'] ?? null, 'course', $unit, $amount, $refused);
            self::addNumeric($byOrganisation, $run['organisation_id'] ?? null, 'organisation', $unit, $amount, $refused);

            $kind = $run['refusal_kind'] ?? null;

            if (\is_string($kind) && $kind !== '') {
                $refusals[$kind] = ($refusals[$kind] ?? 0) + 1;
            }
        }

        $refusalRows = [];

        foreach ($refusals as $kind => $count) {
            $refusalRows[] = ['kind' => (string) $kind, 'count' => $count];
        }

        usort($refusalRows, static fn (array $a, array $b): int => (string) $a['kind'] <=> (string) $b['kind']);

        return [
            'from' => $from,
            'to' => $until,
            'totals' => array_values($totals),
            'by_task' => array_values($byTask),
            'by_placement' => array_values($byPlacement),
            'by_principal' => array_values($byPrincipal),
            'by_course' => array_values($byCourse),
            'by_organisation' => array_values($byOrganisation),
            'refusals' => $refusalRows,
            'oversight' => $this->oversight($from, $until),
            'ceilings' => $this->ceilings(),
            'policy_version' => null,
            'acceptance_count' => 0,
            'unmetered' => !$metered,
        ];
    }

    /**
     * The rubber stamp measurement: accepted, accepted without an edit, and the
     * median edit distance, per task.
     *
     * @return list<array<string, mixed>>
     */
    private function oversight(?string $from, ?string $until): array
    {
        $byTask = [];

        foreach ($this->reads->acceptedDrafts($from, $until) as $draft) {
            $run = $this->reads->run((int) ($draft['run_id'] ?? 0));
            $taskKey = $run === null ? '' : (string) ($run['task_key'] ?? '');

            if ($taskKey === '') {
                continue;
            }

            $ratio = $draft['edit_distance_ratio'] ?? null;
            $ratio = is_numeric($ratio) ? (float) $ratio : null;

            $byTask[$taskKey] ??= ['accepted' => 0, 'unedited' => 0, 'ratios' => []];
            $byTask[$taskKey]['accepted']++;

            if ($ratio === null || $ratio <= 0.0) {
                $byTask[$taskKey]['unedited']++;
            }

            if ($ratio !== null) {
                $byTask[$taskKey]['ratios'][] = $ratio;
            }
        }

        $rows = [];

        foreach ($byTask as $taskKey => $counts) {
            $accepted = (int) $counts['accepted'];
            $unedited = (int) $counts['unedited'];
            /** @var list<float> $ratios */
            $ratios = $counts['ratios'];

            $rows[] = [
                'task_key' => (string) $taskKey,
                'label' => $this->taskLabel((string) $taskKey),
                'accepted' => $accepted,
                'accepted_unedited' => $unedited,
                'accept_unedited_rate' => number_format($unedited / $accepted, 4, '.', ''),
                'median_edit_distance' => self::median($ratios),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => (string) $a['task_key'] <=> (string) $b['task_key']);

        return $rows;
    }

    /**
     * The ceilings and what each one answers right now, read from the policy
     * registry rather than from a list this class keeps: a ninth ceiling is a
     * package, and a report that named them would make it a core edit.
     *
     * @return list<array<string, mixed>>
     */
    private function ceilings(): array
    {
        $rows = [];

        foreach ($this->registries->aiPolicies->all() as $policy) {
            $rows[] = [
                'policy_key' => $policy->key(),
                'label' => method_exists($policy, 'label') ? (string) $policy->label() : $policy->key(),
                'decision' => 'allow',
                'headroom' => null,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => (string) $a['policy_key'] <=> (string) $b['policy_key']);

        return $rows;
    }

    /**
     * @param array<string, array<string, mixed>> $bucket
     */
    private static function add(
        array &$bucket,
        string $key,
        string $label,
        string $unit,
        ?int $amount,
        bool $refused,
    ): void {
        $index = $key . '|' . $unit;

        $bucket[$index] ??= [
            'key' => $key,
            'label' => $label,
            'runs' => 0,
            'refusals' => 0,
            'cost_unit' => $unit,
            'cost_charged' => null,
        ];

        $bucket[$index]['runs'] = (int) $bucket[$index]['runs'] + 1;

        if ($refused) {
            $bucket[$index]['refusals'] = (int) $bucket[$index]['refusals'] + 1;
        }

        if ($amount !== null) {
            $bucket[$index]['cost_charged'] = (int) ($bucket[$index]['cost_charged'] ?? 0) + $amount;
        }
    }

    /**
     * @param array<string, array<string, mixed>> $bucket
     */
    private static function addNumeric(
        array &$bucket,
        mixed $id,
        string $prefix,
        string $unit,
        ?int $amount,
        bool $refused,
    ): void {
        if (!is_numeric($id) || (int) $id <= 0) {
            return;
        }

        self::add($bucket, $prefix . ':' . (int) $id, $prefix . ' ' . (int) $id, $unit, $amount, $refused);
    }

    /**
     * @param list<float> $values
     */
    private static function median(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = \count($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1 ? $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;

        return number_format($median, 4, '.', '');
    }

    private function taskLabel(string $taskKey): string
    {
        if ($taskKey === '' || !$this->registries->aiTasks->has($taskKey)) {
            return $taskKey;
        }

        $task = $this->registries->aiTasks->get($taskKey);

        return $task instanceof AiTaskInterface ? $task->label() : $taskKey;
    }

    private function placementLabel(string $placementKey): string
    {
        if (!$this->registries->aiPlacements->has($placementKey)) {
            return $placementKey;
        }

        $placement = $this->registries->aiPlacements->get($placementKey);

        return $placement instanceof AiPlacementInterface ? $placement->label() : $placementKey;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private static function intOf(array $parameters, string $key): ?int
    {
        $value = $parameters[$key] ?? null;

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
