<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Events\EnrolmentResumed;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * Extending an entitlement is what makes the expiry promise good: suspension
 * never deleted the progress, so a new expiry brings the learner back exactly
 * where they stopped.
 */
final class ExtendEntitlementTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testANewExpiryResumesASuspendedEnrolmentWithItsProgressIntact(): void
    {
        $enrolmentId = $this->enrolmentId();
        $this->recordSomeProgress($enrolmentId);
        $this->suspend($enrolmentId);

        $this->fixture->env->eventBridge->published = [];

        $response = $this->fixture->call(
            'POST',
            '/manage/entitlements/' . $this->fixture->entitlementId . '/extend',
            $this->fixture->teacher(),
            ['expires_at' => '2027-01-01 00:00:00'],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(Entitlement::STATUS_ACTIVE, $response->data['entitlement']['status']);
        self::assertSame('2027-01-01 00:00:00', $response->data['entitlement']['expires_at']);
        self::assertIsArray($response->data['enrolment']);
        self::assertSame(Enrolment::STATUS_ACTIVE, $response->data['enrolment']['status']);

        $resumed = array_filter(
            $this->fixture->env->eventBridge->published,
            static fn (object $event): bool => $event instanceof EnrolmentResumed,
        );
        self::assertCount(1, $resumed, 'Resuming goes through the same path every other grant uses.');

        $progress = $this->fixture->env->database->selectOne(
            Query::from('lesson_progress')->where('enrolment_id', '=', $enrolmentId),
        );
        self::assertNotNull($progress);
        self::assertSame(40.0, (float) $progress['progress_percent'], 'Progress is untouched by any of it.');
    }

    public function testNullMeansNeverExpires(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/entitlements/' . $this->fixture->entitlementId . '/extend',
            $this->fixture->teacher(),
            ['expires_at' => null],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertNull($response->data['entitlement']['expires_at']);
        self::assertSame(Entitlement::STATUS_ACTIVE, $response->data['entitlement']['status']);
    }

    public function testAnExpiryInThePastLeavesTheEntitlementExpired(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/entitlements/' . $this->fixture->entitlementId . '/extend',
            $this->fixture->teacher(),
            ['expires_at' => '2020-01-01 00:00:00'],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(Entitlement::STATUS_EXPIRED, $response->data['entitlement']['status']);
    }

    public function testARevokedEntitlementIsRefused(): void
    {
        $teacher = $this->fixture->teacher();
        $this->fixture->call('POST', '/manage/entitlements/' . $this->fixture->entitlementId . '/revoke', $teacher);

        $response = $this->fixture->call(
            'POST',
            '/manage/entitlements/' . $this->fixture->entitlementId . '/extend',
            $teacher,
            ['expires_at' => '2027-01-01 00:00:00'],
        );

        self::assertSame(409, $response->status);
    }

    public function testAnExpiryThatIsNotAfterTheStartIsRefused(): void
    {
        $this->fixture->env->users->add(70, 'Sam Scheduled', 'sam@example.test');

        $grant = $this->fixture->call(
            'POST',
            $this->fixture->path('/manage/courses/{course}/learners'),
            $this->fixture->teacher(),
            ['user_id' => 70, 'starts_at' => '2030-01-01 00:00:00'],
        );
        self::assertSame(201, $grant->status);
        self::assertNotNull($grant->data);

        $response = $this->fixture->call(
            'POST',
            '/manage/entitlements/' . $grant->data['entitlement']['id'] . '/extend',
            $this->fixture->teacher(),
            ['expires_at' => '2029-01-01 00:00:00'],
        );

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('expires_at', $response->data['errors']);
    }

    public function testAnUnknownEntitlementIsNotFound(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/entitlements/999/extend',
            $this->fixture->manager(),
            ['expires_at' => null],
        );

        self::assertSame(404, $response->status);
    }

    private function enrolmentId(): int
    {
        $row = $this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('user_id', '=', ManagementFixture::LEARNER_ID)
                ->where('course_id', '=', $this->fixture->courseId),
        );

        self::assertNotNull($row);

        return (int) $row['id'];
    }

    private function recordSomeProgress(int $enrolmentId): void
    {
        $now = $this->fixture->env->clock->now()->format('Y-m-d H:i:s');

        $this->fixture->env->database->insert('lesson_progress', [
            'enrolment_id' => $enrolmentId,
            'lesson_id' => $this->fixture->lessonId,
            'user_id' => ManagementFixture::LEARNER_ID,
            'status' => 'in_progress',
            'progress_percent' => 40,
            'seconds_spent' => 90,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function suspend(int $enrolmentId): void
    {
        $this->fixture->env->database->update(
            'enrolments',
            ['status' => Enrolment::STATUS_SUSPENDED],
            ['id' => $enrolmentId],
        );
        $this->fixture->env->database->update(
            'entitlements',
            ['status' => Entitlement::STATUS_EXPIRED, 'expires_at' => '2026-01-14 10:00:00'],
            ['id' => $this->fixture->entitlementId],
        );
    }
}
