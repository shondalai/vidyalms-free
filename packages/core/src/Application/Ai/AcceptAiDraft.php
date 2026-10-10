<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Ai;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiArtefactProvenance;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftRecord;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftRepository;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiProvenanceRepository;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Ai\AcceptedArtefact;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftAcceptorInterface;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftAcceptorRegistry;
use Shondalai\VidyaLMS\Core\Registry\Context\AiAcceptContext;

/**
 * The ONLY writer of an artefact in this whole design.
 *
 * It keeps exactly what only it may own, and delegates the rest:
 *
 * - the human-Principal check, because no risk tier permits automatic
 *   acceptance and a judge that could approve would be the automation-bias
 *   failure with extra steps;
 * - the transaction boundary;
 * - the ai_artefact_provenance write, inside that same boundary;
 * - the disclosure-tier derivation, which is computed and never declared,
 *   because a self-declared label is a label nobody maintains;
 * - the refusal of a draft whose target extension point or provider key is null.
 *
 * **What it writes with is a registered acceptor, not a hardcoded list of use
 * cases.** An earlier shape called AddQuestion, AddAuthoredLesson and the
 * Curriculum use cases by name, which is a switch statement inside a free class:
 * a bank-targeted draft then had no accept path at all, because banks live in the
 * paid package and a free class may not reference a paid use case, and a rubric
 * draft and a translation had no named writer of any kind. The promise of "core
 * files edited: zero" was false the moment a vendor's target was not one of the
 * three the list named.
 *
 * **The generated question is stored only through AddQuestion, never by a table
 * write**, and that is the `question` acceptor's job rather than this class's.
 * Three links of that chain need a provider key to resolve, which is why a draft
 * naming no target is refused here rather than discovered halfway down it.
 *
 * The two provenance rows are both load bearing, and one outlives the other. An
 * accepted draft with no audit row, and an audit row whose content has vanished,
 * both break the provenance claim, so they are written together; the survival is
 * what earns the editorial-responsibility exemption, so the provenance row is
 * excluded from the purge and denormalises what the run would have taken with it.
 */
final class AcceptAiDraft
{
    public function __construct(
        private readonly AiDraftRepository $drafts,
        private readonly AiRunRepository $runs,
        private readonly AiProvenanceRepository $provenance,
        private readonly AiDraftAcceptorRegistry $acceptors,
        private readonly AiAccess $access,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        private readonly int $draftRetentionDays = 90,
    ) {
    }

    public function execute(Principal $principal, int $draftId): AcceptedArtefact
    {
        // A guest cannot be the person holding editorial responsibility, and the
        // check is here rather than in the acceptor so a third-party acceptor
        // cannot skip it.
        $this->access->requireSelf($principal);

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $draft = $this->drafts->find($draftId) ?? throw EntityNotFoundException::for('AI draft', $draftId);
        $this->access->requireAuthoring($principal, $draft->courseId);

        if (!$draft->isAcceptable()) {
            throw new ConflictException(sprintf(
                'AI draft %d names no target extension point or provider key, or is already decided, so it cannot'
                    . ' be accepted. A draft that names no target could never be written through the chain that'
                    . ' validates it.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $draftId,
            ));
        }

        // Golden rule two, at the last door as well as at the registration one.
        // The registry refuses an acceptor that DECLARES a forbidden target,
        // and this refuses a draft that names one however it got there, so an
        // acceptor that declares nothing is still held to the rule.
        $target = strtolower(trim((string) $draft->targetExtensionPoint));

        if (\in_array($target, AiDraftAcceptorRegistry::FORBIDDEN_TARGETS, true)) {
            throw new ConflictException(sprintf(
                'AI draft %d names the extension point "%s". No AI output is written through a grading, '
                . 'availability, credential or enrolment use case, and a human pressing accept does not make '
                . 'one of those a suggestion.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $draftId,
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                (string) $draft->targetExtensionPoint,
            ));
        }

        $acceptor = $this->acceptorFor($draft);
        $run = $this->runs->find($draft->runId);
        $now = $this->clock->now();

        $context = AiAcceptContext::create(
            $principal,
            (string) $draft->targetExtensionPoint,
            (string) $draft->targetProviderKey,
            $run === null ? '' : $run->runKey,
            $now,
        );

        $ratio = AiArtefactProvenance::editDistanceRatio($draft->payload, $draft->effectivePayload());
        $tier = AiArtefactProvenance::tierFor($ratio);
        $retainUntil = $now->modify('+' . max(1, $this->draftRetentionDays) . ' days');

        return $this->database->transactional(function () use (
            $acceptor,
            $draft,
            $context,
            $principal,
            $now,
            $ratio,
            $tier,
            $retainUntil,
            $run
        ): AcceptedArtefact {
            $artefact = $acceptor->accept($draft->forAcceptor(), $context);

            $this->drafts->markAccepted(
                $draft->id,
                $principal->userId,
                $now,
                $retainUntil,
                $artefact->entityType,
                $artefact->entityId,
                $ratio,
                $tier,
            );

            // Inside the SAME transaction as the artefact. Moodle's rule, and
            // the reason is that an accepted draft with no audit row and an audit
            // row whose content vanished both break the same claim.
            $this->provenance->record(
                $artefact->entityType,
                $artefact->entityId,
                $draft->runId,
                $draft->id,
                $run->taskKey ?? 'unknown',
                $run->taskVersion ?? 1,
                $run?->promptRef,
                $run?->model,
                $run->inputsDigest ?? '',
                $tier,
                $ratio,
                $ratio > 0.0,
                $principal->userId,
                $now,
            );

            return $artefact;
        });
    }

    private function acceptorFor(AiDraftRecord $draft): AiDraftAcceptorInterface
    {
        if (!$this->acceptors->has($draft->draftKind)) {
            // The published miss: the draft cannot be accepted, the review screen
            // names the missing package, and nothing is written. Some draft kinds
            // have no acceptor by construction rather than by absence: findings,
            // grouping proposals, question fields, mapping suggestions and
            // narratives are display-only or field-fill, and are never accepted
            // into an artefact at all.
            throw ValidationException::forField('draft_kind', sprintf(
                'No AI draft acceptor is registered for "%s". The package that supplies it is the thing to'
                    . ' install; nothing has been written.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $draft->draftKind,
            ));
        }

        $acceptor = $this->acceptors->get($draft->draftKind);

        if (!$acceptor instanceof AiDraftAcceptorInterface) {
            throw ValidationException::forField('draft_kind', sprintf(
                'The provider registered for draft kind "%s" is not an AI draft acceptor.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                $draft->draftKind,
            ));
        }

        return $acceptor;
    }
}
