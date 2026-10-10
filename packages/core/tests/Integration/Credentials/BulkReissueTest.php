<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Credentials;

use Shondalai\VidyaLMS\Core\Application\Credentials\ReissueCertificate;
use Shondalai\VidyaLMS\Core\Application\Credentials\ReissueCertificatesForTemplate;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Scheduler\ScheduledTask;

/**
 * Bulk reissue, and the two things that have to be stated or a restart
 * double-issues.
 */
final class BulkReissueTest extends CredentialsTestCase
{
    private Principal $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCourse(7, 'Advanced Weaving');
        $this->ports->authorization->grant(42, Capability::MANAGE);
        $this->manager = Principal::user(42);
    }

    public function testTheRunKeyAndThePerCertificateKeyAreBothDeclared(): void
    {
        $templateId = $this->templates->insert('Standard', 'html', 'body', true, 'published');
        $this->issueMany(3);

        $queued = $this->fanOut()->queue($this->manager, $templateId);

        self::assertSame(2, $queued['revision']);
        self::assertSame('reissue:template:' . $templateId . ':2', $queued['dedupe_key']);

        $run = $this->tasksOfType(ReissueCertificatesForTemplate::TASK_TYPE);
        self::assertCount(1, $run);
        self::assertSame($queued['dedupe_key'], $run[0]->dedupeKey);
    }

    public function testTheFanOutSelectsOnlyRowsBelowTheTargetRevision(): void
    {
        $templateId = $this->templates->insert('Standard', 'html', 'body', true, 'published');
        $certificates = $this->issueMany(3);

        $fanOut = $this->fanOut();
        $queued = $fanOut->queue($this->manager, $templateId);
        self::assertSame(3, $fanOut->fanOut($templateId, $queued['revision']));

        $reissue = $this->reissue();

        foreach ($this->tasksOfType(ReissueCertificatesForTemplate::CERTIFICATE_TASK_TYPE) as $task) {
            $reissue->forScheduledTask((int) $task->payload['certificate_id'], (int) $task->payload['revision']);
        }

        // A restart re-runs the fan-out. The reissued rows already carry the
        // new revision, so the predicate skips them: enumerating every
        // certificate for the template instead would re-select the superseded
        // rows the first run produced, and a second correction across five
        // hundred learners would mint two chains each.
        self::assertSame(0, $fanOut->fanOut($templateId, $queued['revision']));

        self::assertSame(6, $this->ports->database->count(Query::from('certificates')));

        foreach ($certificates as $original) {
            $superseded = $this->certificates->findById($original->id);
            self::assertNotNull($superseded);
            self::assertSame(CredentialStatus::Superseded, $superseded->storedStatus);
            self::assertNotNull($superseded->supersededById);
        }
    }

    public function testAPerCertificateTaskReplayedUnderItsOwnKeyIssuesNothingFurther(): void
    {
        $templateId = $this->templates->insert('Standard', 'html', 'body', true, 'published');
        $certificates = $this->issueMany(1);

        $fanOut = $this->fanOut();
        $queued = $fanOut->queue($this->manager, $templateId);
        $fanOut->fanOut($templateId, $queued['revision']);

        $reissue = $this->reissue();
        $reissue->forScheduledTask($certificates[0]->id, $queued['revision']);
        $reissue->forScheduledTask($certificates[0]->id, $queued['revision']);

        self::assertSame(2, $this->ports->database->count(Query::from('certificates')));
    }

    public function testAContinuationCarriesThePointItResumesAfter(): void
    {
        $templateId = $this->templates->insert('Standard', 'html', 'body', true, 'published');
        $fanOut = $this->fanOut();
        $queued = $fanOut->queue($this->manager, $templateId);

        // Sharing one key across batches is the recorded bug this pattern
        // exists to avoid: an adapter upserts on the key, resets the running
        // row to pending, and the run stops after one batch.
        $fanOut->fanOut($templateId, $queued['revision'], 0);
        $keys = array_map(
            static fn (ScheduledTask $task): ?string => $task->dedupeKey,
            $this->tasksOfType(ReissueCertificatesForTemplate::TASK_TYPE),
        );

        self::assertSame(['reissue:template:' . $templateId . ':2'], $keys);
        self::assertNotSame(
            ReissueCertificatesForTemplate::dedupeKeyFor($templateId, 2),
            ReissueCertificatesForTemplate::dedupeKeyFor($templateId, 2, 99),
        );
    }

    public function testQueuedRevisionNeverSilentlyChangesWhenTemplateWasRepublished(): void
    {
        $templateId = $this->templates->insert('Standard', 'html', 'body', true, 'published');
        $certificates = $this->issueMany(1);
        $queued = $this->fanOut()->queue($this->manager, $templateId);
        $this->templates->bumpRevision($templateId, $this->ports->clock->now());
        try {
            $this->reissue()->forScheduledTask($certificates[0]->id, $queued['revision']);
            self::fail('A queued revision must not issue newer source.');
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException) {
            self::assertSame(1, $this->ports->database->count(Query::from('certificates')));
            self::assertNull($this->certificates->findById($certificates[0]->id)?->supersededById);
        }
    }

    public function testCancelTakesTheFanOutKey(): void
    {
        $templateId = $this->templates->insert('Standard', 'html', 'body', true, 'published');
        $fanOut = $this->fanOut();
        $queued = $fanOut->queue($this->manager, $templateId);

        $fanOut->cancel($this->manager, $templateId, $queued['revision']);

        self::assertSame([], $this->tasksOfType(ReissueCertificatesForTemplate::TASK_TYPE));
    }

    /**
     * @return list<\Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate>
     */
    private function issueMany(int $count): array
    {
        $issue = $this->issueWithSnapshot();
        $issued = [];

        for ($i = 0; $i < $count; $i++) {
            $this->ports->users->add(50 + $i, 'Learner ' . $i);
            $issued[] = $issue->execute(100 + $i, 50 + $i, 7);
        }

        return $issued;
    }

    /**
     * @return list<ScheduledTask>
     */
    private function tasksOfType(string $type): array
    {
        return array_values(array_filter(
            $this->ports->scheduler->tasks,
            static fn (ScheduledTask $task): bool => $task->type === $type,
        ));
    }

    private function fanOut(): ReissueCertificatesForTemplate
    {
        return new ReissueCertificatesForTemplate(
            $this->certificates,
            $this->templates,
            $this->ports->scheduler,
            $this->ports->clock,
            $this->guard,
        );
    }

    private function reissue(): ReissueCertificate
    {
        return new ReissueCertificate(
            $this->certificates,
            $this->templates,
            $this->certificateCredits(),
            $this->issuanceSnapshot(),
            $this->guard,
            $this->eventBus,
            $this->ports->clock,
            $this->ports->random,
            null,
            $this->credentialStatus(),
        );
    }
}
