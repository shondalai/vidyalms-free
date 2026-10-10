<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Http\Presenter\AiPresenter;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel;

/**
 * The runs, the jobs and the provenance: every read that answers "what
 * happened" rather than "what may I do".
 *
 * Two scopes, and the difference is the whole authorisation rule. A person
 * reads the runs and jobs they started; a caller holding manage reads every
 * one. The run row is written for every invocation including refused and
 * previewed ones, so the administrator's list is where a site owner sees that
 * ten runs refused for an unaccepted policy rather than seeing nothing at all.
 *
 * The recall is deliberately not here. `RecallAiRun` already owns both halves
 * of it, the preview and the confirmed supersede, and a second listing
 * implementation on this class would be exactly the duplicate that lets one of
 * the two drift.
 */
final class AiRunLog
{
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    public function __construct(
        private readonly SqlAiReadModel $reads,
        private readonly AiAccess $access,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(Principal $principal, int $id): array
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $row = $this->reads->run($id) ?? throw EntityNotFoundException::for('AiRun', $id);
        $owner = $row['principal_user_id'] ?? null;
        $this->access->requireOwnerOrManage($principal, \is_numeric($owner) ? (int) $owner : null);

        return AiPresenter::run(
            $row,
            $this->reads->attempts($id),
            $this->reads->draftsOfRun($id),
            $this->reads->subjectCount($id),
        );
    }

    /**
     * The administrator's list, across every caller.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function runs(Principal $principal, array $filters, int $page, int $perPage): array
    {
        $this->access->requireManage($principal);

        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);
        $result = $this->reads->runPage($filters, $perPage, ($page - 1) * $perPage);

        $items = [];

        foreach ($result['items'] as $row) {
            $items[] = AiPresenter::run($row, $this->reads->attempts((int) $row['id']));
        }

        return AiPresenter::page($items, $result['total'], $page, $perPage);
    }

    /**
     * @return array<string, mixed>
     */
    public function job(Principal $principal, int $id): array
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $row = $this->reads->job($id) ?? throw EntityNotFoundException::for('AiJob', $id);
        $owner = $row['created_by'] ?? null;
        $this->access->requireOwnerOrManage($principal, \is_numeric($owner) ? (int) $owner : null);

        return AiPresenter::job($row);
    }

    /**
     * A caller who does not manage sees their own jobs and nobody else's,
     * which is the filter rather than a refusal: a teacher's job history is a
     * useful screen and another teacher's is not theirs to read.
     *
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    public function jobs(Principal $principal, array $filters, int $page, int $perPage): array
    {
        $scopeId = \is_int($filters['scope_id'] ?? null) ? $filters['scope_id'] : null;
        $scopeType = \is_string($filters['scope_type'] ?? null) ? $filters['scope_type'] : null;
        $this->access->requireAuthoring($principal, $scopeType === 'course' ? $scopeId : null);

        if (!$this->access->manages($principal)) {
            $filters['created_by'] = $principal->userId;
        }

        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $page = max(1, $page);
        $result = $this->reads->jobPage($filters, $perPage, ($page - 1) * $perPage);

        return AiPresenter::page(
            array_map(AiPresenter::job(...), $result['items']),
            $result['total'],
            $page,
            $perPage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function provenance(Principal $principal, string $subjectType, int $subjectId): array
    {
        $rows = $this->reads->provenanceFor($subjectType, $subjectId);

        if ($rows === []) {
            $this->access->requireManage($principal);

            return ['subject_type' => $subjectType, 'subject_id' => $subjectId, 'items' => []];
        }

        $courseId = null;

        foreach ($rows as $row) {
            $run = $this->reads->run((int) ($row['run_id'] ?? 0));

            if ($run !== null && \is_numeric($run['course_id'] ?? null)) {
                $courseId = (int) $run['course_id'];

                break;
            }
        }

        $this->access->requireAuthoring($principal, $courseId);

        return [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'items' => array_map(AiPresenter::provenance(...), $rows),
        ];
    }

    public static function defaultPerPage(): int
    {
        return self::DEFAULT_PER_PAGE;
    }
}
