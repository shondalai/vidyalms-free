<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai;

use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftFindingRecord;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftRecord;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftRepository;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftState;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftCandidate;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

/**
 * Drafts and their findings, over the DatabasePort fluent API.
 *
 * transition() is the only writer of the state column, and it asks the state
 * machine before it writes. That is what makes "nothing becomes a published
 * domain row except through accepted" a property of the storage rather than a
 * rule every caller has to remember, and it is why a rejected draft returns to
 * generated rather than acquiring a seventh state.
 *
 * Every write refreshes retain_until, because setting it only at a terminal
 * state left the drafts nobody ever opened matching no purge predicate at all.
 */
final class SqlAiDraftRepository implements AiDraftRepository
{
    private const DRAFTS = 'ai_drafts';
    private const FINDINGS = 'ai_draft_findings';

    public function __construct(
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
    ) {
    }

    public function record(
        int $runId,
        AiDraftCandidate $candidate,
        \DateTimeImmutable $retainUntil,
        ?int $courseId = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?int $parentDraftId = null,
    ): int {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        return $this->database->insert(self::DRAFTS, [
            'run_id' => $runId,
            'draft_kind' => $candidate->draftKind,
            'target_extension_point' => $candidate->targetExtensionPoint,
            'target_provider_key' => $candidate->targetProviderKey,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'course_id' => $courseId,
            'parent_draft_id' => $parentDraftId,
            'ordinal' => $candidate->ordinal,
            // The candidate's own version, in the envelope and in the mirrored
            // column. It was a hardcoded 1 in both, which made the question
            // acceptor's promise that a draft carries its definition version
            // unkeepable: there was no version to carry.
            'payload' => json_encode(
                StoredShape::wrap($candidate->payload, $candidate->payloadVersion),
                JSON_THROW_ON_ERROR,
            ),
            'payload_version' => $candidate->payloadVersion,
            'citations' => $candidate->citations === []
                ? null
                : json_encode(StoredShape::wrap(['keys' => $candidate->citations], 1), JSON_THROW_ON_ERROR),
            'citations_version' => 1,
            // Its own column so it can never be mistaken for the empirical
            // p-value the stratified composition reads. Both were unwritable
            // until the candidate could carry them.
            'predicted_difficulty' => $candidate->predictedDifficulty,
            'predicted_difficulty_method' => $candidate->predictedDifficultyMethod,
            'state' => AiDraftState::Generated->value,
            'retain_until' => $retainUntil->format('Y-m-d H:i:s'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function transition(
        int $id,
        AiDraftState $to,
        \DateTimeImmutable $now,
        \DateTimeImmutable $retainUntil,
        ?int $reviewerUserId = null,
        ?string $rejectReasonCode = null,
        ?string $rejectReason = null,
    ): void {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $draft = $this->find($id) ?? throw EntityNotFoundException::for('AI draft', $id);

        if ($draft->state === $to) {
            return;
        }

        if (!$draft->state->canBecome($to)) {
            throw new ConflictException(sprintf(
                'An AI draft in state "%s" cannot become "%s".',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $draft->state->value,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $to->value,
            ));
        }

        $stamp = $now->format('Y-m-d H:i:s');
        $values = [
            'state' => $to->value,
            'retain_until' => $retainUntil->format('Y-m-d H:i:s'),
            'updated_at' => $stamp,
        ];

        if ($to === AiDraftState::InReview) {
            $values['reviewer_user_id'] = $reviewerUserId;
            $values['review_started_at'] = $stamp;
        }

        if ($to === AiDraftState::Discarded || $to === AiDraftState::Generated) {
            $values['decided_at'] = $stamp;
            // The corpus that later tunes the critic is the CODE, which is why
            // it is kept when the payload is purged and why free text alone
            // would have been uncountable.
            $values['reject_reason_code'] = $rejectReasonCode;
            $values['reject_reason'] = $rejectReason;
        }

        $this->database->update(self::DRAFTS, $values, ['id' => $id]);
    }

    public function markAccepted(
        int $id,
        int $acceptedBy,
        \DateTimeImmutable $at,
        \DateTimeImmutable $retainUntil,
        string $createdEntityType,
        int $createdEntityId,
        ?float $editDistanceRatio,
        string $disclosureTier,
    ): void {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $draft = $this->find($id) ?? throw EntityNotFoundException::for('AI draft', $id);

        if (!$draft->state->canBecome(AiDraftState::Accepted)) {
            throw new ConflictException(sprintf(
                'An AI draft in state "%s" cannot be accepted.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $draft->state->value,
            ));
        }

        $stamp = $at->format('Y-m-d H:i:s');

        $this->database->update(self::DRAFTS, [
            'state' => AiDraftState::Accepted->value,
            'accepted_by' => $acceptedBy,
            'accepted_at' => $stamp,
            'decided_at' => $stamp,
            'created_entity_type' => $createdEntityType,
            'created_entity_id' => $createdEntityId,
            'edit_distance_ratio' => $editDistanceRatio,
            'disclosure_tier' => $disclosureTier,
            'retain_until' => $retainUntil->format('Y-m-d H:i:s'),
            'updated_at' => $stamp,
        ], ['id' => $id]);
    }

    public function saveEdit(int $id, array $payload, int $version, \DateTimeImmutable $now): void
    {
        $this->database->update(self::DRAFTS, [
            'edited_payload' => json_encode(StoredShape::wrap($payload, $version), JSON_THROW_ON_ERROR),
            'edited_payload_version' => $version,
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ], ['id' => $id]);
    }

    public function find(int $id): ?AiDraftRecord
    {
        $row = $this->database->selectOne(Query::from(self::DRAFTS)->where('id', '=', $id));

        return $row === null ? null : $this->map($row);
    }

    public function forRun(int $runId): array
    {
        return array_map($this->map(...), $this->database->selectAll(
            Query::from(self::DRAFTS)
                ->where('run_id', '=', $runId)
                ->orderBy('ordinal', Query::SORT_ASC)
                ->orderBy('id', Query::SORT_ASC),
        ));
    }

    public function replaceFindings(int $draftId, array $findings): void
    {
        $this->database->delete(self::FINDINGS, ['draft_id' => $draftId]);

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $blocking = 0;
        $advisory = 0;

        foreach ($findings as $finding) {
            $this->database->insert(self::FINDINGS, [
                'draft_id' => $draftId,
                'check_key' => $finding->checkKey,
                'severity' => $finding->severity,
                'code' => $finding->code,
                'path' => $finding->path,
                'message' => $finding->message,
                'evidence' => $finding->evidence === null
                    ? null
                    : json_encode(StoredShape::wrap($finding->evidence, $finding->evidenceVersion), JSON_THROW_ON_ERROR),
                'evidence_version' => $finding->evidenceVersion,
                'acknowledged' => $finding->acknowledged ? 1 : 0,
                'acknowledged_by' => $finding->acknowledgedBy,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($finding->isBlocking()) {
                ++$blocking;
                continue;
            }

            if ($finding->isAdvisory()) {
                ++$advisory;
            }
        }

        // The two counts are real columns because the review queue sorts and
        // filters on them, and a queue that had to count a JSON list per row to
        // order itself is a queue that gets slower as the site gets busier.
        $this->database->update(self::DRAFTS, [
            'findings_blocking' => $blocking,
            'findings_advisory' => $advisory,
            'updated_at' => $now,
        ], ['id' => $draftId]);
    }

    public function idleBefore(\DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->ids(
            Query::from(self::DRAFTS)
                ->select(['id'])
                ->whereIn('state', [
                    AiDraftState::Requested->value,
                    AiDraftState::Generated->value,
                    AiDraftState::Checked->value,
                    AiDraftState::InReview->value,
                ])
                ->where('updated_at', '<', $cutoff->format('Y-m-d H:i:s'))
                ->orderBy('id', Query::SORT_ASC)
                ->limit(max(1, $limit)),
        );
    }

    public function expiredBefore(\DateTimeImmutable $cutoff, int $limit): array
    {
        return $this->ids(
            Query::from(self::DRAFTS)
                ->select(['id'])
                ->where('retain_until', '<', $cutoff->format('Y-m-d H:i:s'))
                ->orderBy('id', Query::SORT_ASC)
                ->limit(max(1, $limit)),
        );
    }

    public function purge(array $draftIds): int
    {
        $purged = 0;

        foreach ($draftIds as $draftId) {
            $this->database->delete(self::FINDINGS, ['draft_id' => $draftId]);
            $purged += $this->database->delete(self::DRAFTS, ['id' => $draftId]);
        }

        return $purged;
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

    /**
     * @param array<string, mixed> $row
     */
    private function map(array $row): AiDraftRecord
    {
        $citations = $this->document($row['citations'] ?? null);
        /** @var list<string> $citationKeys */
        $citationKeys = array_values(array_filter(
            \is_array($citations['keys'] ?? null) ? $citations['keys'] : [],
            static fn (mixed $key): bool => \is_string($key),
        ));

        return new AiDraftRecord(
            (int) $row['id'],
            (int) $row['run_id'],
            (string) $row['draft_kind'],
            $this->nullableString($row['target_extension_point'] ?? null),
            $this->nullableString($row['target_provider_key'] ?? null),
            $this->nullableString($row['subject_type'] ?? null),
            $this->nullableInt($row['subject_id'] ?? null),
            $this->nullableInt($row['course_id'] ?? null),
            $this->nullableInt($row['parent_draft_id'] ?? null),
            (int) ($row['ordinal'] ?? 0),
            $this->document($row['payload'] ?? null) ?? [],
            (int) ($row['payload_version'] ?? 1),
            $this->document($row['edited_payload'] ?? null),
            (int) ($row['edited_payload_version'] ?? 1),
            $citationKeys,
            (int) ($row['citations_version'] ?? 1),
            $this->nullableFloat($row['predicted_difficulty'] ?? null),
            $this->nullableString($row['predicted_difficulty_method'] ?? null),
            AiDraftState::from((string) ($row['state'] ?? AiDraftState::Generated->value)),
            (int) ($row['findings_blocking'] ?? 0),
            (int) ($row['findings_advisory'] ?? 0),
            $this->nullableInt($row['citation_coverage'] ?? null),
            $this->nullableInt($row['reviewer_user_id'] ?? null),
            $this->moment($row['review_started_at'] ?? null),
            $this->moment($row['decided_at'] ?? null),
            $this->nullableString($row['reject_reason_code'] ?? null),
            $this->nullableString($row['reject_reason'] ?? null),
            $this->nullableInt($row['accepted_by'] ?? null),
            $this->moment($row['accepted_at'] ?? null),
            $this->nullableFloat($row['edit_distance_ratio'] ?? null),
            $this->nullableString($row['disclosure_tier'] ?? null),
            $this->nullableString($row['created_entity_type'] ?? null),
            $this->nullableInt($row['created_entity_id'] ?? null),
            $this->moment($row['retain_until'] ?? null) ?? new \DateTimeImmutable('@0'),
            $this->moment($row['created_at'] ?? null),
            $this->moment($row['updated_at'] ?? null),
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
