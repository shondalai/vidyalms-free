<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

/**
 * Where the editorial-responsibility record is written.
 *
 * It is written inside the same transactional() call as the artefact, which is
 * Moodle's rule and is the reason both halves are load bearing: an accepted
 * draft with no audit row, and an audit row whose content has vanished, both
 * break the provenance claim.
 *
 * It is excluded from every purge. There is deliberately no purge method on this
 * interface at all, so a retention sweep that wanted to reach these rows would
 * have to add one and argue for it in review.
 *
 * The screen reads ("what produced this artefact", "the oversight measurement")
 * belong to the AI read model. Only the two the recall path needs are here,
 * because a recall reads and writes in one breath.
 */
interface AiProvenanceRepository
{
    /**
     * Writes one provenance row and returns its id.
     *
     * Unique on (subject_type, subject_id, run_id), so accepting the same draft
     * twice is refused by the database rather than by a read-then-write.
     */
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
    ): int;

    /**
     * Every artefact one run produced, which is what RecallAiRun lists.
     *
     * @return list<AiArtefactProvenance>
     */
    public function forRun(int $runId): array;

    /**
     * Stamps supersededAt on a run's rows, leaving every one of them in place.
     *
     * A recall corrects the artefacts and never deletes the record of what
     * happened, because the record is the thing the Article 50(4) exemption
     * rests on.
     */
    public function supersedeRun(int $runId, \DateTimeImmutable $at): int;
}
