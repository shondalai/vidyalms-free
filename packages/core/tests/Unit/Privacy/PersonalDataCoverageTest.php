<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Privacy;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Organisation\SubjectOrganisationPrivacy;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Privacy\CoreLearningPrivacy;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

final class PersonalDataCoverageTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testExportAndErasureCoverPersonalFieldsAndProtectAnotherLearner(): void
    {
        $db = $this->fixture->env->database;
        $privacy = new CoreLearningPrivacy($db);
        foreach ([50, 51] as $userId) {
            $this->row('grade_cells', ['user_id' => $userId, 'feedback' => 'Private grade feedback']);
            $this->row('grade_changes', ['user_id' => $userId, 'payload' => '{"reason":"Medical accommodation"}']);
            $this->row('notification_consents', ['user_id' => $userId, 'ip' => '192.0.2.' . $userId,
                'evidence' => '{"notice":"accepted"}']);
            $this->row('notification_preferences', ['user_id' => $userId]);
            $this->row('notification_channel_addresses', ['user_id' => $userId]);
            $this->row('credit_scheme_identities', ['user_id' => $userId, 'value' => 'registration-' . $userId]);
            $this->row('learner_tags', ['user_id' => $userId]);
            $this->row('availability_grants', ['user_id' => $userId]);
            $attempt = $this->row('attempts', ['user_id' => $userId]);
            $this->row('attempt_answer_events', ['attempt_id' => $attempt, 'raw_input' => 'Sensitive raw answer']);
            $this->row('attempt_questions', ['attempt_id' => $attempt]);
            $this->row('integrity_events', ['attempt_id' => $attempt, 'context' => '{"ip":"192.0.2.1"}']);
        }
        $exports = [];
        foreach ($privacy->subjectGroups(50, '') as $group) {
            $exports[$group->key] = $db->selectAll(($group->query)());
        }
        self::assertSame('192.0.2.50', $exports['vidyalms-notification-consents'][0]['ip']);
        self::assertSame('Sensitive raw answer', $exports['vidyalms-attempt-answer-events'][0]['raw_input']);
        self::assertSame('Private grade feedback', $exports['vidyalms-grade-cells'][0]['feedback']);
        self::assertCount(1, $exports['vidyalms-grade-cells']);
        $result = $privacy->eraseSubjectRecords(50, 'lena@example.test');
        self::assertSame([], $result['warnings']);
        foreach (['grade_cells', 'grade_changes', 'notification_consents', 'notification_preferences',
            'notification_channel_addresses', 'credit_scheme_identities', 'learner_tags', 'availability_grants'] as $table) {
            self::assertSame(0, $db->count(Query::from($table)->where('user_id', '=', 50)), $table);
            self::assertSame(1, $db->count(Query::from($table)->where('user_id', '=', 51)), $table);
        }
        foreach (['attempt_answer_events', 'attempt_questions', 'integrity_events'] as $table) {
            self::assertSame(1, $db->count(Query::from($table)), $table);
        }
    }

    public function testFailedChildDeleteRetainsParentAndMakesRetryPossible(): void
    {
        $db = $this->fixture->env->database;
        $attempt = $this->row('attempts', ['user_id' => 50]);
        $this->row('attempt_answer_events', ['attempt_id' => $attempt, 'raw_input' => 'private']);
        $db->pdo()->exec("CREATE TRIGGER fail_erasure BEFORE DELETE ON vidyalms_attempt_answer_events BEGIN SELECT RAISE(ABORT, 'temporary failure'); END");
        $privacy = new CoreLearningPrivacy($db);
        self::assertNotEmpty($privacy->eraseSubjectRecords(50, '')['warnings']);
        self::assertSame(1, $db->count(Query::from('attempts')->where('id', '=', $attempt)));
        $db->pdo()->exec('DROP TRIGGER fail_erasure');
        self::assertSame([], $privacy->eraseSubjectRecords(50, '')['warnings']);
        self::assertSame(0, $db->count(Query::from('attempt_answer_events')));
    }

    public function testErasureCannotBeReversedByShopReconciliation(): void
    {
        $f = $this->fixture;
        $f->kernel->syncShopAccess->execute($f->manager(), 'woocommerce', 'order', '100', 50, [$f->courseId], true);
        $privacy = new CoreLearningPrivacy($f->env->database);
        self::assertSame([], $privacy->eraseSubjectRecords(50, '')['warnings']);
        $link = $f->env->database->selectOne(Query::from('shop_access_links'));
        self::assertNotNull($link);
        self::assertSame(0, (int) $link['user_id']);
        self::assertSame('{}', $link['entitlements']);
        self::assertSame('privacy_erased', $link['last_reason']);
        $f->kernel->syncShopAccess->execute($f->manager(), 'woocommerce', 'order', '100', 50, [$f->courseId], true);
        self::assertSame(0, $f->env->database->count(Query::from('entitlements')->where('user_id', '=', 50)));
        // A new purchase is a new relationship and remains supported.
        $f->kernel->syncShopAccess->execute($f->manager(), 'woocommerce', 'order', '101', 50, [$f->courseId], true);
        self::assertSame(1, $f->env->database->count(Query::from('entitlements')->where('user_id', '=', 50)));
    }

    public function testSiteReportAndAiConsentDoNotPermanentlyBlockSubjectErasure(): void
    {
        $f = $this->fixture;
        $this->row('report_runs', ['requested_by' => 50, 'rendered_as' => 50, 'organisation_id' => null,
            'parameters' => '{}', 'storage_path' => 'reports/private.csv', 'state' => 'done']);
        $this->row('ai_policy_acceptances', ['user_id' => 50, 'evidence' => '{"ip":"192.0.2.50"}']);
        $f->env->storage->putPrivate('reports/private.csv', 'personal data');
        $service = new SubjectOrganisationPrivacy($f->kernel->registries->privacyContributors, $f->env->database);
        self::assertFalse($service->requiresReview(50, 'lena@example.test'));
        $result = $service->eraseSubjectRecords(50, 'lena@example.test');
        self::assertSame([], $result['warnings']);
        self::assertSame(0, $f->env->database->count(Query::from('report_runs')));
        self::assertFalse($f->env->storage->exists('reports/private.csv'));
        self::assertSame(0, $f->env->database->count(Query::from('ai_policy_acceptances')));
    }

    public function testAiDerivedDraftsAreErasedAndRetainedAccountingLosesSubjectIdentity(): void
    {
        $f = $this->fixture;
        $runId = $this->row('ai_runs', ['principal_user_id' => 1, 'on_behalf_of_user_id' => 50,
            'subject_type' => 'submission', 'subject_id' => 4, 'error_message' => 'Private response']);
        $this->row('ai_run_subjects', ['run_id' => $runId, 'user_id' => 50]);
        $draft = $this->row('ai_drafts', ['run_id' => $runId, 'payload' => '{"text":"private derived text"}']);
        $this->row('ai_draft_findings', ['draft_id' => $draft]);
        $this->row('ai_artefact_provenance', ['run_id' => $runId, 'accepted_by' => 50]);
        $this->row('ai_drafts', ['run_id' => 999, 'payload' => '{"text":"another learner"}']);
        $privacy = new \Shondalai\VidyaLMS\Core\Provider\Privacy\CoreAiPrivacy($f->env->database);
        $result = $privacy->eraseSubjectRecords(50, 'lena@example.test');
        self::assertSame([], $result['warnings']);
        self::assertSame(0, $f->env->database->count(Query::from('ai_drafts')->where('run_id', '=', $runId)));
        self::assertSame(0, $f->env->database->count(Query::from('ai_draft_findings')));
        self::assertSame(1, $f->env->database->count(Query::from('ai_drafts')->where('run_id', '=', 999)));
        $run = $f->env->database->selectOne(Query::from('ai_runs')->where('id', '=', $runId));
        self::assertNotNull($run);
        self::assertSame(1, (int) $run['principal_user_id']);
        self::assertNull($run['on_behalf_of_user_id']);
        self::assertNull($run['subject_id']);
        self::assertNull($run['error_message']);
        $provenance = $f->env->database->selectOne(Query::from('ai_artefact_provenance'));
        self::assertNotNull($provenance);
        self::assertSame(0, (int) $provenance['accepted_by']);
    }

    public function testErasingAnInstructorPreservesDraftsAboutOtherLearners(): void
    {
        $db = $this->fixture->env->database;
        $runId = $this->row('ai_runs', ['principal_user_id' => 50, 'on_behalf_of_user_id' => 51]);
        $this->row('ai_run_subjects', ['run_id' => $runId, 'user_id' => 51]);
        $draftId = $this->row('ai_drafts', ['run_id' => $runId, 'payload' => '{"text":"Other learner feedback"}']);
        $this->row('ai_draft_findings', ['draft_id' => $draftId, 'acknowledged_by' => 50]);
        $privacy = new \Shondalai\VidyaLMS\Core\Provider\Privacy\CoreAiPrivacy($db);
        self::assertSame([], $privacy->eraseSubjectRecords(50, '')['warnings']);
        self::assertSame(1, $db->count(Query::from('ai_drafts')->where('id', '=', $draftId)));
        self::assertSame(1, $db->count(Query::from('ai_draft_findings')->where('draft_id', '=', $draftId)));
        $run = $db->selectOne(Query::from('ai_runs')->where('id', '=', $runId));
        self::assertNotNull($run);
        self::assertNull($run['principal_user_id']);
        self::assertSame(51, (int) $run['on_behalf_of_user_id']);
    }

    public function testOrganisationAiErasureCannotAffectAnotherTenantOrAnotherSubject(): void
    {
        $f = $this->fixture;
        $db = $f->env->database;
        $org = $f->kernel->organisations->create($f->manager(), ['name' => 'Privacy tenant', 'slug' => 'privacy-tenant']);
        $scope = $f->kernel->organisationAccess->require($f->manager(), (int) $org['id'], 'settings');
        $ours = $this->row('ai_runs', ['run_key' => 'ours', 'organisation_id' => (int) $org['id'], 'principal_user_id' => 1, 'on_behalf_of_user_id' => 50]);
        $otherSubject = $this->row('ai_runs', ['run_key' => 'other-subject', 'organisation_id' => (int) $org['id'], 'principal_user_id' => 50, 'on_behalf_of_user_id' => 51]);
        $otherTenant = $this->row('ai_runs', ['run_key' => 'other-tenant', 'organisation_id' => 999, 'principal_user_id' => 50, 'on_behalf_of_user_id' => 50]);
        foreach ([$ours, $otherSubject, $otherTenant] as $run) {
            $this->row('ai_drafts', ['run_id' => $run, 'payload' => '{"text":"Personal work"}', 'accepted_by' => 50]);
            $this->row('ai_artefact_provenance', ['run_id' => $run, 'accepted_by' => 50, 'subject_id' => $run]);
        }
        $privacy = new \Shondalai\VidyaLMS\Core\Provider\Privacy\CoreAiPrivacy($db);
        $privacy->eraseFor(\Shondalai\VidyaLMS\Core\Registry\Privacy\OrgPrivacyContext::forSubject($scope, 50));
        self::assertSame(0, $db->count(Query::from('ai_drafts')->where('run_id', '=', $ours)));
        foreach ([$otherSubject, $otherTenant] as $run) {
            self::assertSame(1, $db->count(Query::from('ai_drafts')->where('run_id', '=', $run)));
        }
        $outside = $db->selectOne(Query::from('ai_runs')->where('id', '=', $otherTenant));
        self::assertNotNull($outside);
        self::assertSame(50, (int) $outside['principal_user_id']);
        $provenance = $db->selectOne(Query::from('ai_artefact_provenance')->where('run_id', '=', $otherTenant));
        self::assertNotNull($provenance);
        self::assertSame(50, (int) $provenance['accepted_by']);
    }

    public function testAutomationSubjectAndParentReferencesAreExportedAndErased(): void
    {
        $db = $this->fixture->env->database;
        $run = $this->row('automation_runs', ['subject_type' => 'user', 'subject_id' => 50,
            'recipient_user_id' => null, 'log' => 'Private learner event']);
        $otherRun = $this->row('automation_runs', ['subject_type' => 'user', 'subject_id' => 51,
            'recipient_user_id' => null]);
        $this->row('automation_queue', ['run_id' => $run, 'params' => '{"note":"Private work"}']);
        $this->row('automation_action_log', ['run_id' => $run, 'before_state' => '{"tag":"private"}']);
        $this->row('automation_action_log', ['run_id' => $otherRun, 'actor_user_id' => 50]);
        $notification = $this->row('notification_queue', ['subject_type' => 'user', 'subject_id' => 50, 'recipient_user_id' => 51]);
        $privacy = new CoreLearningPrivacy($db);
        $exports = [];
        foreach ($privacy->subjectGroups(50, '') as $group) {
            $exports[$group->key] = $db->selectAll(($group->query)());
        }
        self::assertCount(1, $exports['vidyalms-automation-runs']);
        self::assertCount(1, $exports['vidyalms-automation-action-log']);
        self::assertCount(1, $exports['vidyalms-automation-queue']);
        self::assertSame([], $privacy->eraseSubjectRecords(50, '')['warnings']);
        self::assertSame(0, $db->count(Query::from('automation_queue')));
        self::assertSame(0, $db->count(Query::from('notification_queue')->where('id', '=', $notification)));
        self::assertSame(1, $db->count(Query::from('automation_runs')));
        $otherLog = $db->selectOne(Query::from('automation_action_log')->where('run_id', '=', $otherRun));
        self::assertNotNull($otherLog);
        self::assertSame(0, (int) $otherLog['actor_user_id']);
    }

    public function testInvalidSubmissionPathCannotPreventErasureOrDeleteAnotherFile(): void
    {
        $f = $this->fixture;
        $id = $this->row('submissions', ['user_id' => 50]);
        $this->row('submission_files', ['submission_id' => $id, 'path' => 'submissions/50/../51/private.pdf']);
        $f->env->storage->putPrivate('submissions/51/private.pdf', 'other person');
        $privacy = new CoreLearningPrivacy($f->env->database, $f->env->storage);
        self::assertSame([], $privacy->eraseSubjectRecords(50, '')['warnings']);
        self::assertSame(0, $f->env->database->count(Query::from('submissions')));
        self::assertTrue($f->env->storage->exists('submissions/51/private.pdf'));
    }

    public function testUnavailableStorageKeepsRetryableFileOutboxAfterErasingRecords(): void
    {
        $f = $this->fixture;
        $id = $this->row('submissions', ['user_id' => 50]);
        $this->row('submission_files', ['submission_id' => $id, 'path' => 'submissions/50/private.pdf']);
        $f->env->storage->putPrivate('submissions/50/private.pdf', 'personal work');
        $privacy = new CoreLearningPrivacy($f->env->database);
        self::assertNotEmpty($privacy->eraseSubjectRecords(50, '')['warnings']);
        self::assertSame(0, $f->env->database->count(Query::from('submissions')));
        self::assertSame(1, $f->env->database->count(Query::from('privacy_file_deletions')));
        $restored = new CoreLearningPrivacy($f->env->database, $f->env->storage);
        self::assertSame([], $restored->eraseSubjectRecords(50, '')['warnings']);
        self::assertFalse($f->env->storage->exists('submissions/50/private.pdf'));
        self::assertSame(0, $f->env->database->count(Query::from('privacy_file_deletions')));
    }

    public function testErasureRemovesUnattachedLearnerUploadsAndComplianceHistory(): void
    {
        $f = $this->fixture;
        $f->env->storage->putPrivate('submissions/50/abandoned.pdf', 'draft');
        $f->env->storage->putPrivate('attempts/50/recording.webm', 'recording');
        $f->env->storage->putPrivate('submissions/51/keep.pdf', 'other learner');
        $assignment = $this->row('compliance_assignments', ['user_id' => 50]);
        $this->row('compliance_cycles', ['assignment_id' => $assignment]);
        $this->row('assignment_exceptions', ['user_id' => 50]);
        $privacy = new CoreLearningPrivacy($f->env->database, $f->env->storage);
        self::assertSame([], $privacy->eraseSubjectRecords(50, '')['warnings']);
        self::assertFalse($f->env->storage->exists('submissions/50/abandoned.pdf'));
        self::assertFalse($f->env->storage->exists('attempts/50/recording.webm'));
        self::assertTrue($f->env->storage->exists('submissions/51/keep.pdf'));
        foreach (['compliance_assignments', 'compliance_cycles', 'assignment_exceptions'] as $table) {
            self::assertSame(0, $f->env->database->count(Query::from($table)));
        }
    }

    public function testErasingStaffPreservesSharedScheduledWork(): void
    {
        $db = $this->fixture->env->database;
        $job = $this->row('ai_jobs', ['created_by' => 50]);
        $this->row('ai_job_map', ['job_id' => $job]);
        $this->row('report_schedules', ['created_by' => 50]);
        $privacy = new CoreLearningPrivacy($db);
        self::assertSame([], $privacy->eraseSubjectRecords(50, '')['warnings']);
        self::assertSame(1, $db->count(Query::from('ai_job_map')));
        foreach (['ai_jobs', 'report_schedules'] as $table) {
            self::assertSame(1, $db->count(Query::from($table)->where('created_by', '=', 0)));
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function sharedAiSubjects(): iterable
    {
        yield 'tracked subjects' => [false];
        yield 'on behalf of the requester' => [true];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('sharedAiSubjects')]
    public function testSharedAiDraftRequiresReviewWithoutDestroyingAnotherLearnersWork(bool $onBehalf): void
    {
        $db = $this->fixture->env->database;
        $run = $this->row('ai_runs', ['on_behalf_of_user_id' => $onBehalf ? 50 : null, 'subject_type' => 'course', 'subject_id' => 10]);
        $subjects = $onBehalf ? [51] : [50, 51];
        foreach ($subjects as $user) {
            $this->row('ai_run_subjects', ['run_id' => $run, 'user_id' => $user]);
        }
        $draft = $this->row('ai_drafts', ['run_id' => $run, 'payload' => '{"text":"Shared feedback"}']);
        $privacy = new \Shondalai\VidyaLMS\Core\Provider\Privacy\CoreAiPrivacy($db);
        try {
            $privacy->eraseSubjectRecords(50, '');
            self::fail('Mixed drafts require a review before erasure.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('multiple learners', $error->getMessage());
        }
        self::assertSame(1, $db->count(Query::from('ai_drafts')->where('id', '=', $draft)));
        self::assertSame(count($subjects), $db->count(Query::from('ai_run_subjects')));
        self::assertSame(1, $db->count(Query::from('ai_runs')->where('subject_id', '=', 10)));
    }

    /** @param array<string, mixed> $values */
    private function row(string $table, array $values): int
    {
        $schema = require __DIR__ . '/../../../schema/tables.php';
        foreach ($schema[$table]['columns'] as $column => $spec) {
            if (array_key_exists($column, $values)) {
                continue;
            }
            $type = is_array($spec) ? $spec[0] : $spec;
            if (str_starts_with($type, '?') || (is_array($spec) && array_key_exists('default', $spec))) {
                continue;
            }
            $values[$column] = match (explode(':', $type)[0]) {
                'uint', 'int', 'bigint', 'bool', 'float', 'decimal' => 1,
                'datetime' => '2026-10-05 00:00:00',
                'json' => '{}',
                default => 'test-' . ($values['user_id'] ?? 'row'),
            };
        }
        if ($schema[$table]['timestamps'] ?? true) {
            $values += ['created_at' => '2026-10-05 00:00:00', 'updated_at' => '2026-10-05 00:00:00'];
        }
        return $this->fixture->env->database->insert($table, $values);
    }
}
