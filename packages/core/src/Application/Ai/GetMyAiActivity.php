<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\I18n\TranslatorPort;
use Shondalai\VidyaLMS\Core\Port\Users\UsersPort;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskInterface;
use Shondalai\VidyaLMS\Core\Registry\Registries;

/**
 * The learner's right to know, as a read model rather than a paragraph.
 *
 * It lists every run that touched this learner's data: when, which helper, which
 * model family, **which fields were sent, from the redaction manifest and not
 * from a category description**, who invoked it, whether the output was accepted
 * or discarded, and what is retained until when.
 *
 * **It reads ai_run_subjects and not a single column on the run.**
 * on_behalf_of_user_id answers the single-subject case and is kept as exactly
 * that, but one nullable integer cannot record the subjects of a cohort report
 * narrative, an answer-grouping run over a hundred submissions, a cohort fan-out
 * or a bulk feedback job, which are the runs that touch the most learners. Under
 * that shape every one of those carried NULL or one arbitrary learner, and every
 * other data subject in them got the answer "no runs" from the surface this
 * design offers as its Article 15 answer.
 *
 * It is buildable only because every run is written to the ledger BEFORE the
 * call and updated after it, which is the discipline the notification outbox
 * already applies to mail.
 *
 * **A learner sees their own activity and nobody else's.** The subject id comes
 * from the session principal and never from a parameter, so there is no shape of
 * this call that reads another person's rows.
 *
 * **No draft payload is reachable from here.** The view answers what was SENT and
 * what was DECIDED, and a draft is reachable only under TEACH on the owning
 * course or MANAGE at site scope. A learner-facing surface able to project a
 * draft would put a generated correct answer on a learner's screen, which is the
 * one invariant this whole design is arranged around.
 */
final class GetMyAiActivity
{
    /** The most rows one page may carry. */
    public const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly SqlAiReadModel $reads,
        private readonly AiAccess $access,
        private readonly Registries $registries,
        private readonly ClockPort $clock,
        private readonly UsersPort $users,
        // A task label() answers its catalogue key, so the learner page showed
        // "ai.task.check.readability.label" where the helper name belongs.
        private readonly TranslatorPort $translator,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, per_page: int, as_of: string}
     */
    public function execute(Principal $principal, ?string $from = null, int $page = 1, int $perPage = 20): array
    {
        $userId = $this->access->requireSelf($principal);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);

        $result = $this->reads->activityPage($userId, $from, $perPage, ($page - 1) * $perPage);
        $invokers = $this->namesOf($result['items']);
        $items = [];

        foreach ($result['items'] as $entry) {
            $items[] = $this->present(
                $entry['run'],
                $entry['role'],
                $invokers,
                \is_string($entry['outcome'] ?? null) ? $entry['outcome'] : 'none',
            );
        }

        return [
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'per_page' => $perPage,
            // Stamped, because a person reading their own record needs to know
            // when the answer was true: a purge runs daily and a row that was
            // here this morning may honestly be gone tonight.
            'as_of' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * One row as a learner reads it.
     *
     * The manifest is rendered as the list of fields it actually is. A run whose
     * manifest is absent answers null rather than an empty list, because "nothing
     * was sent" and "we did not record what was sent" are different answers and
     * only one of them is honest here.
     *
     * @param array<string, mixed> $run
     * @param array<int, string>   $invokers
     *
     * @return array<string, mixed>
     */
    private function present(array $run, string $role, array $invokers, string $outcome): array
    {
        $invokedBy = isset($run['principal_user_id']) ? (int) $run['principal_user_id'] : null;

        return [
            'run_id' => (int) ($run['id'] ?? 0),
            'occurred_at' => (string) ($run['created_at'] ?? ''),
            'task_key' => (string) ($run['task_key'] ?? ''),
            'task_label' => $this->labelOf((string) ($run['task_key'] ?? '')),
            'placement_key' => $run['placement_key'] ?? null,
            // The model family as the route recorded it, and the bridge beside
            // it, because "where did my data go" is answered by the pair and not
            // by either alone.
            'model' => $run['model'] ?? null,
            'bridge_key' => (string) ($run['bridge_key'] ?? 'null'),
            'invoked_by' => $invokedBy,
            'invoked_by_name' => $invokedBy === null ? null : ($invokers[$invokedBy] ?? null),
            'actor_kind' => (string) ($run['actor_kind'] ?? 'person'),
            'my_role' => $role,
            'fields_sent' => $this->manifestOf($run),
            'state' => (string) ($run['state'] ?? ''),
            // What became of the output, which is a different fact from the
            // run's own state and the last element of the design's list of
            // what a learner is shown. A succeeded run whose every draft a
            // reviewer discarded used to read as succeeded and nothing else.
            'outcome' => $outcome,
            'was_preview' => ((int) ($run['dry_run'] ?? 0)) === 1,
            'retained_until' => (string) ($run['retain_until'] ?? ''),
        ];
    }

    /**
     * The label of the task that ran, or null when nothing registers its key any
     * more.
     *
     * A missing label is the published registry miss and not an error: a drafted
     * run stays readable and correctly attributed after the package that produced
     * it is removed, which is exactly why the AI tables are free tables read by
     * free code paths.
     */
    private function labelOf(string $taskKey): ?string
    {
        if ($taskKey === '' || !$this->registries->aiTasks->has($taskKey)) {
            return null;
        }

        $task = $this->registries->aiTasks->get($taskKey);

        return $task instanceof AiTaskInterface ? $this->translator->translate($task->label()) : null;
    }

    /**
     * @param list<array{run: array<string, mixed>, role: string}> $entries
     *
     * @return array<int, string>
     */
    private function namesOf(array $entries): array
    {
        $ids = [];

        foreach ($entries as $entry) {
            $invoker = $entry['run']['principal_user_id'] ?? null;

            if ($invoker !== null) {
                $ids[] = (int) $invoker;
            }
        }

        $ids = array_values(array_unique($ids));

        return $ids === [] ? [] : $this->users->namesFor($ids);
    }

    /**
     * @param array<string, mixed> $run
     *
     * @return list<string>|null
     */
    private function manifestOf(array $run): ?array
    {
        $manifest = $run['redaction_manifest'] ?? null;

        if (!\is_string($manifest) || $manifest === '') {
            return null;
        }

        /** @var mixed $decoded */
        $decoded = json_decode($manifest, true);

        if (!\is_array($decoded)) {
            return null;
        }

        /** @var mixed $fields */
        $fields = $decoded['fields'] ?? null;

        if (!\is_array($fields)) {
            return null;
        }

        return array_values(array_filter($fields, static fn (mixed $field): bool => \is_string($field)));
    }
}
