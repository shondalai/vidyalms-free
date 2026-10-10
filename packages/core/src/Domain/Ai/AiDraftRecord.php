<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Ai;

use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraft;

/**
 * A stored draft row, with everything the review queue and the purge read.
 *
 * It is deliberately a different object from Registry\Ai\AiDraft, which is the
 * narrow shape an acceptor is handed. An acceptor receives the payload and the
 * target and never the run, the reviewer, the retention instant or the findings
 * counts, so that an acceptor cannot re-read the prompt or the cost on its way
 * to a domain write. This is the row; that is the seam.
 *
 * The synthetic id convention lives here rather than in the review screen.
 * PublicationFinding carries a nullable questionId, a transient Question built
 * from a draft is constructed with id null exactly as AddQuestion does, and a
 * check writing questionId would then emit null for every draft: a run over
 * twenty drafted items would produce twenty findings none of which names an
 * item. So a draft-built question is given the stable synthetic id
 * -{draftId}, the two id spaces being disjoint under the negation, and the
 * response envelope translates it back.
 */
final class AiDraftRecord
{
    /** @var array<string, mixed> */
    public readonly array $payload;

    /** @var array<string, mixed>|null */
    public readonly ?array $editedPayload;

    /** @var list<string> */
    public readonly array $citations;

    /**
     * @param array<string, mixed>      $payload
     * @param array<string, mixed>|null $editedPayload
     * @param list<string>              $citations
     */
    public function __construct(
        public readonly int $id,
        public readonly int $runId,
        public readonly string $draftKind,
        public readonly ?string $targetExtensionPoint,
        public readonly ?string $targetProviderKey,
        public readonly ?string $subjectType,
        public readonly ?int $subjectId,
        public readonly ?int $courseId,
        public readonly ?int $parentDraftId,
        public readonly int $ordinal,
        array $payload,
        public readonly int $payloadVersion,
        ?array $editedPayload,
        public readonly int $editedPayloadVersion,
        array $citations,
        public readonly int $citationsVersion,
        public readonly ?float $predictedDifficulty,
        public readonly ?string $predictedDifficultyMethod,
        public readonly AiDraftState $state,
        public readonly int $findingsBlocking,
        public readonly int $findingsAdvisory,
        public readonly ?int $citationCoverage,
        public readonly ?int $reviewerUserId,
        public readonly ?\DateTimeImmutable $reviewStartedAt,
        public readonly ?\DateTimeImmutable $decidedAt,
        public readonly ?string $rejectReasonCode,
        public readonly ?string $rejectReason,
        public readonly ?int $acceptedBy,
        public readonly ?\DateTimeImmutable $acceptedAt,
        public readonly ?float $editDistanceRatio,
        public readonly ?string $disclosureTier,
        public readonly ?string $createdEntityType,
        public readonly ?int $createdEntityId,
        public readonly \DateTimeImmutable $retainUntil,
        public readonly ?\DateTimeImmutable $createdAt = null,
        public readonly ?\DateTimeImmutable $updatedAt = null,
    ) {
        $this->payload = $payload;
        $this->editedPayload = $editedPayload;
        $this->citations = $citations;
    }

    /**
     * What is actually accepted: the reviewer's edit when they made one, and the
     * generated payload when they did not.
     *
     * @return array<string, mixed>
     */
    public function effectivePayload(): array
    {
        return $this->editedPayload ?? $this->payload;
    }

    /** The narrow shape an acceptor is handed. */
    public function forAcceptor(): AiDraft
    {
        return AiDraft::of(
            $this->id,
            $this->runId,
            $this->draftKind,
            $this->effectivePayload(),
            $this->targetExtensionPoint,
            $this->targetProviderKey,
            $this->citations,
            $this->ordinal,
            null,
            $this->editedPayload === null ? $this->payloadVersion : $this->editedPayloadVersion,
        );
    }

    /**
     * The stable synthetic question id a draft-built PublicationContext uses.
     *
     * Negative because the two id spaces are disjoint under the negation, and
     * because Question::withId(int) accepts any int, so nothing had to be
     * widened to make this work.
     */
    public function syntheticQuestionId(): int
    {
        return -$this->id;
    }

    /** The draft a synthetic question id names, or null when it names a real one. */
    public static function draftIdFromSyntheticQuestionId(?int $questionId): ?int
    {
        return $questionId !== null && $questionId < 0 ? -$questionId : null;
    }

    public function isAcceptable(): bool
    {
        return $this->targetExtensionPoint !== null
            && $this->targetProviderKey !== null
            && $this->state->canBecome(AiDraftState::Accepted);
    }
}
