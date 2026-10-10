<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Ai;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiArtefactProvenance;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiDraftState;
use Shondalai\VidyaLMS\Core\Domain\Ai\AiRunReservation;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiDraftCandidate;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The only writer of an artefact, and the two provenance rows one of which
 * outlives the other.
 *
 * The cases here are the four things acceptance owns and cannot delegate: the
 * human check, the transaction, the provenance write and the refusal of a draft
 * naming no target. The domain write itself belongs to a registered acceptor,
 * which is what keeps the accept path open to a draft kind a package added.
 */
final class AcceptAiDraftTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->fixture->env->clock->set('2026-06-01 09:00:00');
    }

    /**
     * The flagship path: a question draft becomes a question through AddQuestion
     * and through nothing else, and the provenance row lands beside it.
     */
    public function testAcceptingAQuestionDraftWritesTheQuestionAndItsProvenanceRow(): void
    {
        [$runId, $draftId] = $this->draft(['quiz_id' => $this->fixture->quizId] + $this->definition());

        $before = $this->fixture->env->database->count(Query::from('questions'));
        $artefact = $this->fixture->kernel->acceptAiDraft->execute($this->fixture->teacher(), $draftId);

        self::assertSame('question', $artefact->entityType);
        self::assertGreaterThan(0, $artefact->entityId);
        self::assertSame(
            $before + 1,
            $this->fixture->env->database->count(Query::from('questions')),
            'The question is stored through AddQuestion, which is the chain that validates it.',
        );

        $provenance = $this->fixture->kernel->aiProvenanceStore->forRun($runId);
        self::assertCount(1, $provenance);
        self::assertSame('question', $provenance[0]->subjectType);
        self::assertSame($artefact->entityId, $provenance[0]->subjectId);
        self::assertSame($this->fixture->teacher()->userId, $provenance[0]->acceptedBy);
        // The tier is DERIVED from the edit distance, never declared: an
        // unedited draft is fully AI generated and the record says so.
        self::assertSame(AiArtefactProvenance::TIER_FULLY_GENERATED, $provenance[0]->disclosureTier);
        self::assertFalse($provenance[0]->humanEdited);

        $draft = $this->fixture->kernel->aiDraftStore->find($draftId);
        self::assertNotNull($draft);
        self::assertSame(AiDraftState::Accepted, $draft->state);
        self::assertSame('question', $draft->createdEntityType);
        self::assertSame($artefact->entityId, $draft->createdEntityId);
    }

    /**
     * The rubber-stamp metric is what the site owner is shown beside the spend,
     * so an edited draft has to be distinguishable from an unedited one.
     */
    public function testAnEditedDraftIsRecordedAtADifferentDisclosureTier(): void
    {
        [$runId, $draftId] = $this->draft(['quiz_id' => $this->fixture->quizId] + $this->definition());

        $edited = ['quiz_id' => $this->fixture->quizId] + $this->definition('A completely rewritten stem about tea.');
        $this->fixture->kernel->aiDraftStore->saveEdit($draftId, $edited, 1, new \DateTimeImmutable('2026-06-01 10:00:00'));

        $this->fixture->kernel->acceptAiDraft->execute($this->fixture->teacher(), $draftId);

        $provenance = $this->fixture->kernel->aiProvenanceStore->forRun($runId);
        self::assertCount(1, $provenance);
        self::assertTrue($provenance[0]->humanEdited);
        self::assertNotSame(AiArtefactProvenance::TIER_FULLY_GENERATED, $provenance[0]->disclosureTier);
        self::assertNotNull($provenance[0]->editDistanceRatio);
        self::assertGreaterThan(0.0, $provenance[0]->editDistanceRatio);
    }

    /**
     * Three links of the AddQuestion chain need a provider key to resolve, so a
     * draft without one could never be accepted at all. It is refused here rather
     * than discovered halfway down that chain.
     */
    public function testADraftNamingNoTargetIsRefusedAndNothingIsWritten(): void
    {
        [, $draftId] = $this->draft($this->definition(), targeted: false);

        $before = $this->fixture->env->database->count(Query::from('questions'));

        $this->expectException(ConflictException::class);

        try {
            $this->fixture->kernel->acceptAiDraft->execute($this->fixture->teacher(), $draftId);
        } finally {
            self::assertSame($before, $this->fixture->env->database->count(Query::from('questions')));
            self::assertSame(0, $this->fixture->env->database->count(Query::from('ai_artefact_provenance')));
        }
    }

    /**
     * The published miss: nothing is written and the reviewer is told which
     * package supplies the acceptor.
     */
    public function testADraftKindNoAcceptorSuppliesIsRefusedByName(): void
    {
        [, $draftId] = $this->draft($this->definition(), kind: 'acme.bank_entry');

        try {
            $this->fixture->kernel->acceptAiDraft->execute($this->fixture->teacher(), $draftId);
            self::fail('A draft kind no acceptor supplies must be refused.');
        } catch (ValidationException $refused) {
            // The reviewer is told which key had no acceptor, so the answer is
            // "install the package that supplies it" rather than "it did not work".
            self::assertStringContainsString('acme.bank_entry', implode(' ', $refused->errors));
        }

        self::assertSame(0, $this->fixture->env->database->count(Query::from('ai_artefact_provenance')));
    }

    /**
     * No risk tier permits automatic acceptance, so a guest cannot be the person
     * holding editorial responsibility.
     */
    public function testAGuestCannotAcceptADraft(): void
    {
        [, $draftId] = $this->draft(['quiz_id' => $this->fixture->quizId] + $this->definition());

        $this->expectException(\Throwable::class);

        $this->fixture->kernel->acceptAiDraft->execute(
            \Shondalai\VidyaLMS\Core\Application\Shared\Principal::guest(),
            $draftId,
        );
    }

    /**
     * The record that survives: the run is purged and the provenance row is not.
     */
    public function testTheRetentionPurgeRemovesTheRunAndLeavesTheProvenanceRow(): void
    {
        [$runId, $draftId] = $this->draft(['quiz_id' => $this->fixture->quizId] + $this->definition());
        $this->fixture->kernel->acceptAiDraft->execute($this->fixture->teacher(), $draftId);

        self::assertSame(1, $this->fixture->env->database->count(Query::from('ai_artefact_provenance')));

        // Move the run's retention instant into the past and sweep.
        $this->fixture->env->database->update(
            'ai_runs',
            ['retain_until' => '2020-01-01 00:00:00'],
            ['id' => $runId],
        );
        $this->fixture->env->database->update(
            'ai_drafts',
            ['retain_until' => '2020-01-01 00:00:00'],
            ['id' => $draftId],
        );

        $sweep = new \Shondalai\VidyaLMS\Core\Application\Ai\AiRetentionSweep(
            $this->fixture->kernel->aiRunStore,
            $this->fixture->kernel->aiDraftStore,
            $this->fixture->env->clock,
        );
        $result = $sweep->run();

        self::assertSame(1, $result['runs_purged']);
        self::assertSame(0, $this->fixture->env->database->count(Query::from('ai_runs')));
        self::assertSame(0, $this->fixture->env->database->count(Query::from('ai_drafts')));
        // The editorial-responsibility record outlives the prompt log that
        // produced it. That is what earns the Article 50(4) exemption, and it is
        // enforced by the provenance repository having no purge method at all.
        self::assertSame(1, $this->fixture->env->database->count(Query::from('ai_artefact_provenance')));
    }

    /**
     * Recall lists what a run produced, stamps every row superseded and never
     * deletes one.
     */
    public function testRecallStampsProvenanceSupersededAndLeavesEveryRowInPlace(): void
    {
        [$runId, $draftId] = $this->draft(['quiz_id' => $this->fixture->quizId] + $this->definition());
        $this->fixture->kernel->acceptAiDraft->execute($this->fixture->teacher(), $draftId);

        $preview = $this->fixture->kernel->recallAiRun->preview($this->fixture->manager(), $runId);
        self::assertCount(1, $preview['artefacts']);
        self::assertSame(['question' => 1], $preview['by_type']);

        $result = $this->fixture->kernel->recallAiRun->execute(
            $this->fixture->manager(),
            $runId,
            \Shondalai\VidyaLMS\Core\Application\Ai\RecallAiRun::CONFIRMATION,
            'A prompt change produced subtly bad items.',
        );

        self::assertSame(1, $result['superseded']);
        self::assertSame(1, $this->fixture->env->database->count(Query::from('ai_artefact_provenance')));

        $rows = $this->fixture->kernel->aiProvenanceStore->forRun($runId);
        self::assertNotNull($rows[0]->supersededAt);

        // The note lands on the run's only free-text column, and error_code is
        // left alone so a query counting real errors still counts only those.
        $run = $this->fixture->kernel->aiRunStore->find($runId);
        self::assertNotNull($run);
        self::assertNull($run->errorCode);
        self::assertStringContainsString('Recalled by user', (string) $run->errorMessage);
    }

    /** A typed confirmation, because a recall is a bulk state change. */
    public function testRecallRefusesWithoutTheTypedConfirmation(): void
    {
        [$runId] = $this->draft(['quiz_id' => $this->fixture->quizId] + $this->definition());

        $this->expectException(ValidationException::class);

        $this->fixture->kernel->recallAiRun->execute($this->fixture->manager(), $runId, 'yes please');
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $prompt = 'Which leaf makes tea?'): array
    {
        return [
            'prompt' => $prompt,
            'options' => [
                ['key' => 'a', 'label' => 'Camellia sinensis'],
                ['key' => 'b', 'label' => 'Rosa canina'],
            ],
            'correct' => ['a'],
        ];
    }

    /**
     * Writes a reserved run and one draft on it, which is what the pipeline does
     * and what the accept path needs in front of it.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: int, 1: int}
     */
    private function draft(array $payload, bool $targeted = true, string $kind = 'question'): array
    {
        $now = new \DateTimeImmutable('2026-06-01 09:00:00');

        $runId = $this->fixture->kernel->aiRunStore->reserve(
            AiRunReservation::create(
                'run-' . bin2hex(random_bytes(8)),
                'question.template_aig',
                hash('sha256', 'inputs'),
                $now->modify('+400 days'),
                1,
                null,
                null,
                null,
                'null',
                null,
                1,
                $this->fixture->teacher()->userId,
                AiRunReservation::ACTOR_PERSON,
                null,
                null,
                'quiz',
                $this->fixture->quizId,
                $this->fixture->courseId,
            ),
        );

        $draftId = $this->fixture->kernel->aiDraftStore->record(
            $runId,
            AiDraftCandidate::create(
                $kind,
                $targeted ? 'question_type' : '',
                $payload,
                $targeted ? 'multiple_choice' : null,
            ),
            $now->modify('+90 days'),
            $this->fixture->courseId,
        );

        return [$runId, $draftId];
    }
}
