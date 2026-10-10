<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiProvenanceRepository;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * Lists and supersedes everything one run produced.
 *
 * The provenance ledger already makes "every artefact that came from run X" a
 * one-index query, so this is a shipped management action rather than a thing to
 * be discovered during an incident. The most likely first real incident is a
 * prompt change that produced fifty subtly bad items across four courses.
 *
 * It is designed rather than improvised, and three of its rules matter:
 *
 * - it **never invents a second delete path**. It lists what the run produced,
 *   grouped by entity type, and the operator undoes each through the normal path
 *   for that entity: unpublish a lesson, revert a bank to its previous version,
 *   archive a draft course. A recall that deleted rows itself would be a second
 *   writer of every content table in the product;
 * - it requires MANAGE and a typed confirmation, because it is a bulk state
 *   change;
 * - it leaves every provenance row in place with supersededAt stamped, because
 *   the record of what happened must survive the correction. That is the whole
 *   point of the row outliving the ledger.
 *
 * The note it writes lands on the run's error_message, which is the only
 * free-text column ai_runs has. error_code is deliberately left alone, so a
 * query counting real errors still counts only those.
 */
final class RecallAiRun
{
    /** What the operator must type, so a bulk state change is never one click. */
    public const CONFIRMATION = 'RECALL';

    public function __construct(
        private readonly AiRunRepository $runs,
        private readonly AiProvenanceRepository $provenance,
        private readonly AiAccess $access,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * What this run produced, so an operator can see the blast radius before
     * confirming anything.
     *
     * @return array{
     *     run_id: int,
     *     run_key: string,
     *     task_key: string,
     *     artefacts: list<array{subject_type: string, subject_id: int, accepted_by: int, accepted_at: string, superseded: bool}>,
     *     by_type: array<string, int>
     * }
     */
    public function preview(Principal $principal, int $runId): array
    {
        $this->access->requireManage($principal);

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $run = $this->runs->find($runId) ?? throw EntityNotFoundException::for('AI run', $runId);
        $artefacts = [];
        $byType = [];

        foreach ($this->provenance->forRun($runId) as $row) {
            $artefacts[] = [
                'subject_type' => $row->subjectType,
                'subject_id' => $row->subjectId,
                'accepted_by' => $row->acceptedBy,
                'accepted_at' => $row->acceptedAt->format('Y-m-d H:i:s'),
                'superseded' => $row->supersededAt !== null,
            ];
            $byType[$row->subjectType] = ($byType[$row->subjectType] ?? 0) + 1;
        }

        return [
            'run_id' => $run->id,
            'run_key' => $run->runKey,
            'task_key' => $run->taskKey,
            'artefacts' => $artefacts,
            'by_type' => $byType,
        ];
    }

    /**
     * Stamps every provenance row of the run superseded and notes the recall on
     * the run.
     *
     * @return array<string, mixed> the preview above, plus the number of
     *                              provenance rows stamped superseded
     */
    public function execute(Principal $principal, int $runId, string $confirmation, string $reason = ''): array
    {
        $this->access->requireManage($principal);

        if ($confirmation !== self::CONFIRMATION) {
            throw ValidationException::forField('confirmation', sprintf(
                'A recall is a bulk state change, so it is confirmed by typing "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                self::CONFIRMATION,
            ));
        }

        $preview = $this->preview($principal, $runId);
        $now = $this->clock->now();

        $superseded = $this->database->transactional(function () use ($runId, $now, $principal, $reason): int {
            $count = $this->provenance->supersedeRun($runId, $now);

            $this->runs->note($runId, sprintf(
                'Recalled by user %d at %s. %d provenance row(s) stamped superseded and left in place, because'
                    . ' the record of what happened has to survive the correction. Each artefact is undone through'
                    . ' the normal path for its own entity type.%s',
                $principal->userId,
                $now->format('Y-m-d H:i:s'),
                $count,
                $reason === '' ? '' : ' Reason: ' . $reason,
            ));

            return $count;
        });

        return $preview + ['superseded' => $superseded];
    }
}
