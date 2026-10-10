<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiArtefactProvenance;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiProvenanceRepository;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The editorial-responsibility record, over the DatabasePort fluent API.
 *
 * There is no delete and no purge here, and the absence is the mechanism: a
 * retention sweep that wanted to reach these rows would have to add a method and
 * argue for it in review, which is exactly the friction the design asks for
 * around the record that earns the Article 50(4) exemption.
 *
 * The row is written inside the accept use case's transactional() call, together
 * with the artefact itself. The unique index on (subject_type, subject_id,
 * run_id) is what refuses a double acceptance, at the database rather than in a
 * read-then-write that has a window in it.
 */
final class SqlAiProvenanceRepository implements AiProvenanceRepository
{
    private const PROVENANCE = 'ai_artefact_provenance';

    public function __construct(private readonly DatabasePort $database)
    {
    }

    public function record(
        string $subjectType,
        int $subjectId,
        int $runId,
        ?int $draftId,
        string $taskKey,
        int $taskVersion,
        ?string $promptRef,
        ?string $model,
        string $inputsDigest,
        string $disclosureTier,
        ?float $editDistanceRatio,
        bool $humanEdited,
        int $acceptedBy,
        \DateTimeImmutable $acceptedAt,
    ): int {
        return $this->database->insert(self::PROVENANCE, [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'run_id' => $runId,
            'draft_id' => $draftId,
            // Denormalised from the run on purpose: the run row is subject to the
            // retention purge and this row is not, and a record whose task and
            // model vanished after a hundred and eighty days is not a record.
            'task_key' => $taskKey,
            'task_version' => $taskVersion,
            'prompt_ref' => $promptRef,
            'model' => $model,
            'inputs_digest' => $inputsDigest,
            'disclosure_tier' => $disclosureTier,
            'edit_distance_ratio' => $editDistanceRatio,
            'human_edited' => $humanEdited ? 1 : 0,
            'accepted_by' => $acceptedBy,
            // The row carries no created_at: ai_artefact_provenance is
            // timestamps-false, because a ledger row is never updated and
            // accepted_at is the instant that matters.
            'accepted_at' => $acceptedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function forRun(int $runId): array
    {
        return array_map($this->map(...), $this->database->selectAll(
            Query::from(self::PROVENANCE)->where('run_id', '=', $runId)->orderBy('id', Query::SORT_ASC),
        ));
    }

    public function supersedeRun(int $runId, \DateTimeImmutable $at): int
    {
        // Stamped and never deleted. The record of what happened has to survive
        // the correction, which is the whole reason a recall is a designed
        // management action rather than a thing improvised during an incident.
        return $this->database->update(
            self::PROVENANCE,
            ['superseded_at' => $at->format('Y-m-d H:i:s')],
            ['run_id' => $runId],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): AiArtefactProvenance
    {
        return new AiArtefactProvenance(
            (int) $row['id'],
            (string) $row['subject_type'],
            (int) $row['subject_id'],
            (int) $row['run_id'],
            isset($row['draft_id']) ? (int) $row['draft_id'] : null,
            (string) ($row['task_key'] ?? ''),
            (int) ($row['task_version'] ?? 1),
            isset($row['prompt_ref']) ? (string) $row['prompt_ref'] : null,
            isset($row['model']) ? (string) $row['model'] : null,
            (string) ($row['inputs_digest'] ?? ''),
            (string) ($row['disclosure_tier'] ?? AiArtefactProvenance::TIER_DOMINANT_HUMAN_SUPERVISED),
            isset($row['edit_distance_ratio'])
                ? (float) $row['edit_distance_ratio']
                : null,
            (bool) ($row['human_edited'] ?? false),
            (int) ($row['accepted_by'] ?? 0),
            new \DateTimeImmutable((string) ($row['accepted_at'] ?? '@0')),
            isset($row['superseded_at']) && \is_string($row['superseded_at']) && $row['superseded_at'] !== ''
                ? new \DateTimeImmutable($row['superseded_at'])
                : null,
        );
    }
}
