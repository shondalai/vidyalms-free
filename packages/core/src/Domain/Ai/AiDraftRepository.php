<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftCandidate;

/**
 * Where drafts and their findings are WRITTEN.
 *
 * A core-owned port. The one rule it carries that no caller may work around:
 * **the state column moves through AiDraftState and nothing writes it
 * directly**, so "nothing becomes a published domain row except through
 * accepted" is expressible rather than repeated in every reader.
 *
 * retain_until is written at insert and refreshed on every state change, which
 * is why every transition method takes it. Setting it only at a terminal state
 * left every draft in requested, generated, checked or in_review matching no
 * purge predicate at all: exactly the rows nobody decided about, which for a
 * feedback draft hold a learner's submission-derived text.
 */
interface AiDraftRepository
{
    /**
     * Writes one candidate as a draft row and returns its id.
     *
     * Called by the pipeline and by nothing else: a task that wrote a draft
     * itself would escape the run's own ledger, and a draft with no run has no
     * provenance to inherit when somebody accepts it.
     */
    public function record(
        int $runId,
        AiDraftCandidate $candidate,
        \DateTimeImmutable $retainUntil,
        ?int $courseId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $parentDraftId = null,
    ): int;

    /**
     * Moves a draft to a new state, refusing a transition the machine forbids.
     *
     * @throws \Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException on a
     *                                                                 transition
     *                                                                 the state
     *                                                                 machine
     *                                                                 refuses
     */
    public function transition(
        int $id,
        AiDraftState $to,
        \DateTimeImmutable $now,
        \DateTimeImmutable $retainUntil,
        ?int $reviewerUserId = null,
        ?string $rejectReasonCode = null,
        ?string $rejectReason = null,
    ): void;

    /**
     * Records the acceptance facts on the draft itself, beside the provenance
     * row the accept use case writes in the same transaction.
     */
    public function markAccepted(
        int $id,
        int $acceptedBy,
        \DateTimeImmutable $at,
        \DateTimeImmutable $retainUntil,
        string $createdEntityType,
        int $createdEntityId,
        ?float $editDistanceRatio,
        string $disclosureTier,
    ): void;

    /**
     * Replaces the reviewer's edit before acceptance.
     *
     * @param array<string, mixed> $payload
     */
    public function saveEdit(int $id, array $payload, int $version, \DateTimeImmutable $now): void;

    public function find(int $id): ?AiDraftRecord;

    /**
     * The drafts one run produced, in ordinal order.
     *
     * @return list<AiDraftRecord>
     */
    public function forRun(int $runId): array;

    /**
     * Replaces the findings of one draft and refreshes its two counts, which the
     * queue sorts and filters on.
     *
     * @param list<AiDraftFindingRecord> $findings
     */
    public function replaceFindings(int $draftId, array $findings): void;

    /**
     * Drafts nobody has touched since an instant, in a non-terminal state.
     *
     * @return list<int>
     */
    public function idleBefore(\DateTimeImmutable $cutoff, int $limit): array;

    /**
     * Drafts whose retention instant has passed.
     *
     * @return list<int>
     */
    public function expiredBefore(\DateTimeImmutable $cutoff, int $limit): array;

    /**
     * Deletes drafts and their findings. Provenance rows are never touched.
     *
     * @param list<int> $draftIds
     */
    public function purge(array $draftIds): int;
}
