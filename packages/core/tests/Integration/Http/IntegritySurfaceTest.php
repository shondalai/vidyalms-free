<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Presentation\AccessibilityProfile;
use Shondalai\VidyaLMS\Core\Registry\AttemptPolicy\AttemptPolicySet;

/**
 * Integrity signals are evidence, never enforcement.
 *
 * Nothing in these tests asserts that a signal changed anything, because
 * nothing may: a blur is as likely to be a docked magnifier, an on-screen
 * keyboard or a read-aloud application as a second window, and a product
 * that acted on one would punish exactly the learners the accessibility work
 * exists to serve.
 */
final class IntegritySurfaceTest extends TestCase
{
    private ManagementFixture $fixture;
    private int $attemptId = 0;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->attemptId = $this->startAttempt();
    }

    public function testARunnerRecordsOneAdvisorySignalOnItsOwnAttempt(): void
    {
        $response = $this->record(['kind' => 'window_blur', 'context' => ['seconds_hidden' => 4]]);

        self::assertSame(201, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));
        self::assertNotNull($response->data);
        self::assertTrue($response->data['recorded']);

        $event = $response->data['event'];
        self::assertSame(['id', 'attempt_id', 'kind', 'actor', 'occurred_at', 'context'], array_keys($event));
        self::assertSame('window_blur', $event['kind']);
        self::assertSame('system', $event['actor'], 'The actor is always the system, never a person.');
        self::assertSame(['seconds_hidden' => 4], $event['context']);
    }

    public function testRecordingASignalChangesNothingAboutTheAttempt(): void
    {
        $before = $this->fixture->call('GET', '/attempts/' . $this->attemptId, $this->fixture->learner());
        $this->record(['kind' => 'visibility_hidden']);
        $after = $this->fixture->call('GET', '/attempts/' . $this->attemptId, $this->fixture->learner());

        self::assertSame($before->data, $after->data);
    }

    public function testAnUnknownKindIsRefusedAndAVendorNamespacedOneIsNot(): void
    {
        self::assertSame(422, $this->record(['kind' => 'looked_suspicious'])->status);
        self::assertSame(201, $this->record(['kind' => 'x_acme_second_face'])->status);
    }

    public function testTheClientClockCannotWriteEvidenceOutsideTheAttempt(): void
    {
        $attempt = $this->fixture->call('GET', '/attempts/' . $this->attemptId, $this->fixture->learner());
        $startedAt = $attempt->data['attempt']['started_at'] ?? null;

        $early = $this->record(['kind' => 'window_blur', 'occurred_at' => '2001-01-01 00:00:00']);
        self::assertSame(201, $early->status);
        self::assertSame(
            $startedAt,
            $early->data['event']['occurred_at'] ?? null,
            'Anything before the attempt started is clamped to its start.',
        );

        $late = $this->record(['kind' => 'window_focus', 'occurred_at' => '2099-01-01 00:00:00']);
        self::assertSame(201, $late->status);
        self::assertSame(
            $this->fixture->env->clock->now()->format('Y-m-d H:i:s'),
            $late->data['event']['occurred_at'] ?? null,
            'And anything in the future is clamped to now.',
        );
    }

    public function testRecordingIsSuppressedEntirelyForADeclaredAccessibilityProfile(): void
    {
        $this->freezeProfile([AccessibilityProfile::MAGNIFICATION => true]);

        $response = $this->record(['kind' => 'window_blur']);

        self::assertSame(201, $response->status);
        self::assertNotNull($response->data);
        self::assertFalse($response->data['recorded']);
        self::assertArrayHasKey('event', $response->data);
        self::assertNull($response->data['event']);

        $listed = $this->list($this->fixture->teacher());
        self::assertSame(0, $listed->data['total'] ?? null, 'Suppressed means not written, not written and hidden.');
    }

    public function testAnotherLearnerCannotReportOnSomeoneElsesAttempt(): void
    {
        self::assertSame(403, $this->fixture->call(
            'POST',
            '/attempts/' . $this->attemptId . '/integrity-events',
            $this->fixture->teacher(),
            ['kind' => 'window_blur'],
        )->status);

        self::assertSame(401, $this->fixture->call(
            'POST',
            '/attempts/' . $this->attemptId . '/integrity-events',
            Principal::guest(),
            ['kind' => 'window_blur'],
        )->status);
    }

    public function testASubmittedAttemptRecordsNothingFurther(): void
    {
        $this->fixture->call('POST', '/attempts/' . $this->attemptId . '/submit', $this->fixture->learner());

        self::assertSame(409, $this->record(['kind' => 'window_blur'])->status);
    }

    public function testTheLedgerReadsOldestFirstForAHumanWhoMayGrade(): void
    {
        $this->record(['kind' => 'window_blur']);
        $this->fixture->env->clock->advance('PT1M');
        $this->record(['kind' => 'window_focus']);

        $response = $this->list($this->fixture->teacher());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['items', 'total', 'page', 'per_page'], array_keys($response->data));
        self::assertSame(2, $response->data['total']);
        self::assertSame(
            ['window_blur', 'window_focus'],
            array_map(static fn (array $event): string => $event['kind'], $response->data['items']),
        );
    }

    public function testTheLedgerIsNotTheLearnersToRead(): void
    {
        self::assertSame(403, $this->list($this->fixture->learner())->status);
        self::assertSame(401, $this->list(Principal::guest())->status);
        self::assertSame(403, $this->list($this->fixture->outsider())->status);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function record(array $body): \Shondalai\VidyaLMS\Core\Http\ApiResponse
    {
        return $this->fixture->call(
            'POST',
            '/attempts/' . $this->attemptId . '/integrity-events',
            $this->fixture->learner(),
            $body,
        );
    }

    private function list(Principal $principal): \Shondalai\VidyaLMS\Core\Http\ApiResponse
    {
        return $this->fixture->call(
            'GET',
            '/manage/attempts/' . $this->attemptId . '/integrity-events',
            $principal,
        );
    }

    /**
     * @param array<string, mixed> $elements
     */
    private function freezeProfile(array $elements): void
    {
        // The profile is read once at StartAttempt and frozen with the rest
        // of the policy, so the fixture writes the frozen row rather than
        // reaching for a learner profile source the free build has no route
        // to author.
        $snapshot = AttemptPolicySet::deferredFeedback()
            ->withAccessibilityProfile(AccessibilityProfile::of($elements))
            ->toArray();

        $this->fixture->env->database->update(
            'attempts',
            ['policy_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR)],
            ['id' => $this->attemptId],
        );
    }

    private function startAttempt(): int
    {
        $this->fixture->env->clock->advance('PT1M');

        $attempt = $this->fixture->call(
            'POST',
            '/quizzes/' . $this->fixture->quizId . '/attempts',
            $this->fixture->learner(),
        );

        self::assertSame(201, $attempt->status, json_encode($attempt->data, JSON_THROW_ON_ERROR));

        return (int) ($attempt->data['id'] ?? 0);
    }
}
