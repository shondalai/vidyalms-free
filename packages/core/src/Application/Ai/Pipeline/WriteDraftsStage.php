<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai\Pipeline;

use Shondalai\VidyaLMS\Core\Application\Platform\ImportNote;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftFindingRecord;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftRepository;
use Shondalai\VidyaLMS\Core\Registry\Ai\DeclaresDraftRetention;
use Shondalai\VidyaLMS\Core\Registry\Publication\PublicationFinding;

/**
 * Stage 11. Turns the candidates into draft rows, and the findings into their
 * own rows.
 *
 * **A draft is not a row in the questions table with a flag on it.** A generated
 * definition contains correct answers by construction, and the product's
 * strongest invariant is that quiz correctness never leaves the server before an
 * attempt is submitted, so the draft lives here and is reachable only under
 * TEACH. A flag on a live row is one forgotten condition away from a leak, which
 * is the departure from Blackboard this design names.
 *
 * Findings are rows and not a JSON list on the draft, because they are read from
 * OUTSIDE the draft: "which check is producing the most noise" is the question
 * that tunes the pipeline, and a JSON column answers it with a scan.
 *
 * The retention instant is set at insert and refreshed on every state change,
 * because setting it only at a terminal state left every draft in requested,
 * generated, checked or in_review matching no purge predicate at all: exactly the
 * rows nobody decided about, which for a feedback draft hold a learner's
 * submission-derived text.
 *
 * A dry run writes nothing here, which is what "the dry run costs nothing" means
 * on the storage side as well as on the bill.
 */
final class WriteDraftsStage implements AiRunStageInterface
{
    /**
     * The default retention for a draft payload: ninety days, against the run's
     * four hundred.
     *
     * They differ because one number is not honest. A discarded feedback draft
     * about a named learner is personal data with no remaining purpose, and so is
     * one nobody ever opened; an accepted draft's payload has no remaining
     * purpose either, because the artefact now lives in its own table with its
     * own history while the editorial-responsibility record is what survives.
     */
    public const DEFAULT_DRAFT_RETENTION_DAYS = 90;

    /**
     * The lawful basis a draft is kept under while a person decides about it.
     *
     * Article 6(1)(b): the draft is the evidence of the editorial act a person
     * is in the middle of, and it goes when that act ends or when the window
     * does. It is named here because no AI record class declared a basis at
     * all while learning, organisations and the ledger redaction store all
     * did, and a row kept under no basis is a row nobody can defend.
     */
    public const DRAFT_RETENTION_BASIS = \Shondalai\VidyaLMS\Core\Registry\Privacy\RetentionBasis::CONTRACT;

    public function __construct(
        private readonly AiDraftRepository $drafts,
        private readonly int $retentionDays = self::DEFAULT_DRAFT_RETENTION_DAYS,
    ) {
    }

    public function name(): string
    {
        return 'WriteDrafts';
    }

    public function runsAfterHalt(): bool
    {
        return false;
    }

    public function handle(AiPipelineRun $run): void
    {
        $result = $run->result;

        if ($result === null || $run->runId === null || $run->request->dryRun) {
            return;
        }

        $byOrdinal = [];

        foreach ($result->drafts as $candidate) {
            $parentId = $candidate->parentOrdinal === null
                ? null
                : ($byOrdinal[$candidate->parentOrdinal] ?? null);

            $draftId = $this->drafts->record(
                $run->runId,
                $candidate,
                $this->retainUntil($run, $candidate->draftKind),
                $run->request->courseId,
                $run->request->task->subjectType,
                $run->request->task->subjectId,
                // parent_draft_id is what makes a course build a TREE of drafts,
                // so a reviewer accepts module by module rather than accepting or
                // discarding one payload whole.
                $parentId,
            );

            $byOrdinal[$candidate->ordinal] = $draftId;
            $run->draftIds[] = $draftId;
        }

        $this->attachFindings($run, $result->findings, $byOrdinal);
    }

    /**
     * When a draft of this kind stops being kept.
     *
     * The site's default, or a SHORTER answer the producing task declared. A
     * longer one is not honoured: a task may not extend how long personal data
     * it produced is held beyond what the site decided, and a glossary
     * suggestion asking for a year would otherwise outlive the feedback draft
     * derived from a named learner's submission.
     */
    private function retainUntil(AiPipelineRun $run, string $draftKind): \DateTimeImmutable
    {
        $days = max(1, $this->retentionDays);
        $task = $run->task;

        if ($task instanceof DeclaresDraftRetention) {
            $declared = $task->draftRetentionDays($draftKind);

            if ($declared !== null && $declared >= 1) {
                $days = min($days, $declared);
            }
        }

        return $run->now->modify('+' . $days . ' days');
    }

    /**
     * Findings a task computed, filed against the draft each names.
     *
     * The synthetic negative id convention is what makes this possible: a
     * transient question built from a draft is given the id -{draftId}, so a
     * check writing questionId names an item rather than emitting null for every
     * draft in the run. A finding naming no question is filed against the first
     * draft, because a run over twenty items whose findings name none of them is
     * the failure the convention exists to stop.
     *
     * @param list<PublicationFinding> $findings
     * @param array<int, int>          $byOrdinal
     */
    private function attachFindings(AiPipelineRun $run, array $findings, array $byOrdinal): void
    {
        if ($findings === [] || $byOrdinal === []) {
            return;
        }

        $perDraft = [];
        $fallback = (int) reset($byOrdinal);

        foreach ($findings as $finding) {
            $draftId = $this->draftIdOf($finding, $run->draftIds) ?? $fallback;
            $perDraft[$draftId][] = AiDraftFindingRecord::of(
                $draftId,
                // The check key is the task that produced the finding.
                // PublicationFinding carries no check key of its own, and
                // inventing a second vocabulary for the same idea is how a
                // reviewer meets two findings languages in one screen.
                $run->request->taskKey,
                $this->severityOf($finding),
                $finding->code,
                $finding->messageKey,
                null,
                $finding->parameters === [] ? null : ['parameters' => $finding->parameters],
            );
        }

        foreach ($perDraft as $draftId => $records) {
            $this->drafts->replaceFindings($draftId, $records);
        }

        $run->note(ImportNote::INFO, sprintf(
            '%d finding(s) recorded across %d draft(s).',
            \count($findings),
            \count($perDraft),
        ));
    }

    /**
     * The three published severities, read off the one findings vocabulary the
     * author already meets at publish time.
     *
     * blocks maps to blocking and acknowledge to advisory, because both are
     * things a reviewer has to act on; a plain warning is info, because it is
     * the level the publication chain itself treats as advice.
     */
    private function severityOf(PublicationFinding $finding): string
    {
        return match (true) {
            $finding->isBlocking() => AiDraftFindingRecord::SEVERITY_BLOCKING,
            $finding->needsAcknowledgement() => AiDraftFindingRecord::SEVERITY_ADVISORY,
            default => AiDraftFindingRecord::SEVERITY_INFO,
        };
    }

    /**
     * @param list<int> $draftIds
     */
    private function draftIdOf(PublicationFinding $finding, array $draftIds): ?int
    {
        $questionId = $finding->questionId;

        if ($questionId === null || $questionId >= 0) {
            return null;
        }

        $draftId = -$questionId;

        return \in_array($draftId, $draftIds, true) ? $draftId : null;
    }
}
