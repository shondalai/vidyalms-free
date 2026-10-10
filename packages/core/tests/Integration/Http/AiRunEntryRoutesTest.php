<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * The four entry points that reach the pipeline, through the real routed
 * kernel, on a build that has bought nothing.
 *
 * Every one of them answers with a real row rather than a 404, and the answer
 * is a refusal. That is the free build working, not the free build failing: no
 * route is configured, so every task refuses `not_configured` after the run has
 * been written, the manifest rendered and the reservation released. A site that
 * never buys Pro still has the ledger, the dry run and the drafts.
 *
 * The refusals are the assertions that matter. An estimate that could be talked
 * into spending, a job queued without a scope, and an accept that walks past
 * blocking findings are each a control that exists only if it is exercised from
 * the failing side.
 */
final class AiRunEntryRoutesTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    /* ── The estimate ───────────────────────────────────────────────── */

    public function testTheEstimateWritesAPreviewedRunAndCallsNoBridge(): void
    {
        $response = $this->fixture->call('POST', '/ai/estimate', $this->fixture->manager(), [
            'task_key' => 'check.schema_validity',
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
            'course_id' => $this->fixture->courseId,
            'scope_type' => 'course',
            'scope_id' => $this->fixture->courseId,
        ]);

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('check.schema_validity', $response->data['task_key']);
        self::assertFalse($response->data['route_configured'], 'a free site has no enabled route');
        self::assertIsInt($response->data['run_id']);
        self::assertArrayHasKey('estimate', $response->data);
        self::assertArrayHasKey('unit', $response->data['estimate']);

        $run = $this->fixture->call(
            'GET',
            '/manage/ai/runs/' . $response->data['run_id'],
            $this->fixture->manager(),
        );

        self::assertSame(200, $run->status);
        self::assertNotNull($run->data);
        self::assertTrue($run->data['dry_run'], 'the estimate forces the dry run rather than trusting the payload');
        self::assertSame('null', $run->data['bridge_key'], 'the free build runs over the null bridge');
    }

    public function testTheEstimateCannotBeTalkedIntoSpending(): void
    {
        $response = $this->fixture->call('POST', '/ai/estimate', $this->fixture->manager(), [
            'task_key' => 'check.schema_validity',
            'subject_type' => 'course',
            'subject_id' => $this->fixture->courseId,
            // The flag the other way. The route forces the dry run regardless.
            'dry_run' => false,
        ]);

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);

        $run = $this->fixture->call(
            'GET',
            '/manage/ai/runs/' . $response->data['run_id'],
            $this->fixture->manager(),
        );

        self::assertNotNull($run->data);
        self::assertTrue($run->data['dry_run']);
    }

    public function testTheEstimateRefusesAGuestAndALearner(): void
    {
        $body = ['task_key' => 'check.schema_validity', 'subject_type' => 'course'];

        self::assertSame(401, $this->fixture->call('POST', '/ai/estimate', Principal::guest(), $body)->status);
        self::assertSame(403, $this->fixture->call('POST', '/ai/estimate', $this->fixture->learner(), $body)->status);
    }

    public function testTheEstimateNamesTheFieldItNeeds(): void
    {
        $response = $this->fixture->call('POST', '/ai/estimate', $this->fixture->manager(), ['subject_type' => 'course']);

        self::assertSame(422, $response->status);
    }

    /* ── The interactive run ────────────────────────────────────────── */

    public function testAnInteractiveRunAnswersWithARowAndATypedRefusal(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/ai/runs',
            $this->fixture->manager(),
            [
                'task_key' => 'check.schema_validity',
                'subject_type' => 'course',
                'subject_id' => $this->fixture->courseId,
                'course_id' => $this->fixture->courseId,
            ],
            [],
            'run-1',
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertNotNull($response->data['refusal'], 'no route is configured, so the run refuses');
        self::assertContains(
            $response->data['refusal']['kind'],
            [
                'not_configured',
                'unsupported_capability',
                'ceiling_exceeded',
                'upstream_rate_limited',
                'upstream_unavailable',
                'upstream_refused',
                'input_rejected',
                'input_too_large',
                'schema_invalid',
                'moderation_blocked',
                'deadline_exceeded',
                'cancelled',
            ],
            'a refusal is one of the closed twelve, never a free string a provider invented',
        );
        self::assertSame('refused', $response->data['state']);
        self::assertArrayNotHasKey('score', $response->data);

        $log = $this->fixture->call('GET', '/manage/ai/runs', $this->fixture->manager());
        self::assertNotNull($log->data);
        self::assertSame(1, $log->data['total'], 'a refused run is written to the ledger like any other');
    }

    public function testAnInteractiveRunRequiresAnIdempotencyKey(): void
    {
        $response = $this->fixture->call('POST', '/ai/runs', $this->fixture->manager(), [
            'task_key' => 'check.schema_validity',
            'subject_type' => 'course',
        ]);

        self::assertSame(400, $response->status);
    }

    /* ── The queued job ─────────────────────────────────────────────── */

    public function testQueueingAJobAnswersTwoHundredAndTwoWithTheRow(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/ai/jobs',
            $this->fixture->manager(),
            [
                'task_key' => 'check.schema_validity',
                'scope_type' => 'course',
                'scope_id' => $this->fixture->courseId,
            ],
            [],
            'job-1',
        );

        self::assertSame(202, $response->status, 'the job answers immediately, exactly as an import does');
        self::assertNotNull($response->data);
        self::assertSame('pending', $response->data['status']);
        self::assertSame('check.schema_validity', $response->data['task_key']);
        self::assertNull($response->data['recipe_key'], 'exactly one of the two is set');
        self::assertSame(ManagementFixture::MANAGER_ID, $response->data['created_by']);

        $listed = $this->fixture->call('GET', '/ai/jobs', $this->fixture->manager());
        self::assertNotNull($listed->data);
        self::assertSame(1, $listed->data['total']);

        $one = $this->fixture->call('GET', '/ai/jobs/' . $response->data['id'], $this->fixture->manager());
        self::assertSame(200, $one->status);
    }

    public function testAJobNamingNothingRegisteredIsRefusedAtTheDoor(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/ai/jobs',
            $this->fixture->manager(),
            [
                'task_key' => 'nobody.ships.this',
                'scope_type' => 'course',
                'scope_id' => $this->fixture->courseId,
            ],
            [],
            'job-bad',
        );

        self::assertSame(422, $response->status);
    }

    public function testAJobRefusesAFieldTheContractDoesNotName(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/ai/jobs',
            $this->fixture->manager(),
            ['task_key' => 'check.schema_validity', 'scope_type' => 'course'],
            [],
            'job-noscope',
        );

        self::assertSame(422, $response->status, 'a job names what it runs over');
    }

    public function testAnotherTeachersJobIsNotInMyList(): void
    {
        $this->fixture->call(
            'POST',
            '/ai/jobs',
            $this->fixture->teacher(),
            [
                'task_key' => 'check.schema_validity',
                'scope_type' => 'course',
                'scope_id' => $this->fixture->courseId,
            ],
            [],
            'job-teacher',
        );

        $mine = $this->fixture->call(
            'GET',
            '/ai/jobs',
            $this->fixture->outsider(),
            [],
            ['scope_type' => 'course', 'scope_id' => (string) $this->fixture->otherCourseId],
        );

        self::assertSame(200, $mine->status);
        self::assertNotNull($mine->data);
        self::assertSame(0, $mine->data['total']);
    }

    /* ── Accepting a draft ──────────────────────────────────────────── */

    public function testAcceptingADraftWithBlockingFindingsNeedsASecondDecision(): void
    {
        $draftId = $this->seedDraft(1);

        $refused = $this->fixture->call(
            'POST',
            '/ai/drafts/' . $draftId . '/accept',
            $this->fixture->teacher(),
            [],
            [],
            'accept-1',
        );

        self::assertSame(409, $refused->status, 'rejection is the default state of a draft');
    }

    public function testADraftWhoseAcceptorNoPackageAnswersIsRefusedRatherThanApproximated(): void
    {
        $draftId = $this->seedDraft(0, 'nobody.ships.this');

        $response = $this->fixture->call(
            'POST',
            '/ai/drafts/' . $draftId . '/accept',
            $this->fixture->teacher(),
            [],
            [],
            'accept-orphan',
        );

        self::assertSame(
            422,
            $response->status,
            'the draft names a kind no acceptor answers, so nothing is written and the refusal names it',
        );
    }

    public function testAcceptingRequiresAnIdempotencyKeyAndRefusesALearner(): void
    {
        $draftId = $this->seedDraft(0);

        self::assertSame(
            400,
            $this->fixture->call('POST', '/ai/drafts/' . $draftId . '/accept', $this->fixture->teacher())->status,
        );
        self::assertSame(
            403,
            $this->fixture->call(
                'POST',
                '/ai/drafts/' . $draftId . '/accept',
                $this->fixture->learner(),
                [],
                [],
                'accept-learner',
            )->status,
        );
    }

    private function seedDraft(int $blocking, string $kind = 'question'): int
    {
        $database = $this->fixture->env->ports->database;

        $runId = $database->insert('ai_runs', [
            'run_key' => 'run-' . bin2hex(random_bytes(4)),
            'task_key' => 'check.schema_validity',
            'task_version' => 1,
            'bridge_key' => 'null',
            'policy_version' => 0,
            'principal_user_id' => ManagementFixture::TEACHER_ID,
            'actor_kind' => 'person',
            'course_id' => $this->fixture->courseId,
            'locale' => 'en-GB',
            'inputs_digest' => str_repeat('a', 64),
            'redaction_manifest_version' => 1,
            'dry_run' => 0,
            'state' => 'succeeded',
            'cost_unit' => 'none',
            'retain_until' => '2027-01-01 00:00:00',
            'created_at' => '2026-09-08 09:00:00',
            'updated_at' => '2026-09-08 09:00:00',
        ]);

        return $database->insert('ai_drafts', [
            'run_id' => $runId,
            'draft_kind' => $kind,
            'target_extension_point' => 'QuestionType',
            'target_provider_key' => 'multiple_choice',
            'course_id' => $this->fixture->courseId,
            'ordinal' => 0,
            'payload' => json_encode(['_v' => 1, 'stem' => 'Original stem.'], \JSON_THROW_ON_ERROR),
            'payload_version' => 1,
            'state' => 'generated',
            'findings_blocking' => $blocking,
            'findings_advisory' => 0,
            'retain_until' => '2026-12-08 00:00:00',
            'created_at' => '2026-09-08 09:00:00',
            'updated_at' => '2026-09-08 09:00:00',
        ]);
    }
}
