<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

final class SyncShopAccessTest extends TestCase
{
    private ManagementFixture $f;

    protected function setUp(): void
    {
        $this->f = ManagementFixture::create();
        $this->f->env->users->add(60, 'Shop learner', 'learner@example.test');
        $this->f->env->users->add(61, 'Other learner', 'other@example.test');
    }

    public function testPaidCourseBundleIsIdempotentAndBothShopsHaveSeparateIdentity(): void
    {
        $this->f->env->eventBridge->published = [];
        $this->f->env->scheduler->tasks = [];
        foreach (['easycommerce', 'woocommerce'] as $shop) {
            foreach ([1, 2, 3] as $_) {
                $this->sync([$this->f->courseId, $this->f->otherCourseId], shop: $shop);
            }
        }
        self::assertCount(4, $this->grants());
        self::assertSame(2, $this->f->env->database->count(Query::from('enrolments')->where('user_id', '=', 60)));
        self::assertSame(2, $this->f->env->database->count(Query::from('shop_access_links')));
        self::assertNotEmpty($this->f->env->eventBridge->published);
        self::assertNotEmpty($this->f->env->scheduler->tasks, 'Committed enrolments must schedule their notification intents.');
    }

    public function testPartialRefundOnlyRemovesTheAffectedCourseAndReactivationKeepsProgress(): void
    {
        $this->sync([$this->f->courseId, $this->f->otherCourseId]);
        $enrolment = $this->enrolment($this->f->courseId);
        $this->f->env->database->insert('lesson_progress', [
            'enrolment_id' => $enrolment['id'], 'lesson_id' => $this->f->lessonId, 'user_id' => 60,
            'progress_percent' => 40, 'status' => 'in_progress',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        $this->sync([$this->f->otherCourseId]);
        self::assertSame('suspended', $this->enrolment($this->f->courseId)['status']);
        self::assertSame('active', $this->enrolment($this->f->otherCourseId)['status']);
        $this->sync([$this->f->courseId, $this->f->otherCourseId]);
        self::assertSame($enrolment['id'], $this->enrolment($this->f->courseId)['id']);
        self::assertSame('active', $this->enrolment($this->f->courseId)['status']);
        $progress = $this->f->env->database->selectOne(Query::from('lesson_progress')->where('enrolment_id', '=', $enrolment['id']));
        self::assertNotNull($progress);
        self::assertSame(40.0, (float) $progress['progress_percent']);
        self::assertCount(3, $this->grants(), 'The withdrawn grant remains in the history.');
    }

    public function testCancellationDoesNotRemoveAccessFromAnotherPurchaseOrManualGrant(): void
    {
        $this->sync([$this->f->courseId]);
        $this->sync([$this->f->courseId], objectId: '2');
        $this->sync([], active: false);
        self::assertSame('active', $this->enrolment($this->f->courseId)['status']);
        $this->f->kernel->grantEntitlement->execute($this->f->manager(), 60, $this->f->courseId, Entitlement::SOURCE_MANUAL);
        $this->sync([], active: false, objectId: '2');
        self::assertSame('active', $this->enrolment($this->f->courseId)['status']);
    }

    public function testSubscriptionRenewalUpdatesExistingGrantAndAnEndedTermSuspendsAccess(): void
    {
        $this->sync([$this->f->courseId], type: 'subscription', expiry: '2030-01-01 00:00:00');
        $this->sync([$this->f->courseId], type: 'subscription', expiry: '2031-01-01 00:00:00');
        self::assertCount(1, $this->grants());
        self::assertSame('2031-01-01 00:00:00', $this->grants()[0]['expires_at']);
        $this->sync([$this->f->courseId], type: 'subscription', expiry: '2020-01-01 00:00:00');
        self::assertSame('suspended', $this->enrolment($this->f->courseId)['status']);
        $this->sync([$this->f->courseId], type: 'subscription', expiry: '2032-01-01 00:00:00');
        self::assertSame('active', $this->enrolment($this->f->courseId)['status']);
        self::assertCount(2, $this->grants());
    }

    public function testChangingCustomerWithdrawsThePreviousCustomersGrant(): void
    {
        $this->sync([$this->f->courseId]);
        $this->sync([$this->f->courseId], userId: 61);
        self::assertSame('suspended', $this->enrolment($this->f->courseId)['status']);
        self::assertSame('active', $this->enrolment($this->f->courseId, 61)['status']);
    }

    public function testNoGuestOrEmailBasedGrant(): void
    {
        $this->expectException(ValidationException::class);
        try {
            $this->sync([$this->f->courseId], userId: 0);
        } finally {
            self::assertCount(0, $this->grants());
            self::assertSame(0, $this->f->env->database->count(Query::from('shop_access_links')));
        }
    }

    public function testLearnerCannotGrantThemselvesPurchasedAccess(): void
    {
        try {
            $this->sync([$this->f->courseId], actor: Principal::user(60));
            self::fail('A learner must not grant paid access.');
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException) {
            self::assertCount(0, $this->grants());
            self::assertSame(0, $this->f->env->database->count(Query::from('shop_access_links')));
        }
    }

    public function testNativePaymentAndOrderRoutesAreAbsent(): void
    {
        foreach (['/manage/orders', '/commerce/orders', '/commerce/checkout', '/commerce/webhooks/stripe'] as $path) {
            $method = str_contains($path, 'checkout') || str_contains($path, 'webhooks') ? 'POST' : 'GET';
            self::assertSame(404, $this->f->call($method, $path, $this->f->manager())->status, $path);
        }
    }

    public function testFailedBundleDoesNotPublishAnEnrolmentThatRolledBack(): void
    {
        $this->f->env->database->update('courses', ['state' => 'archived'], ['id' => $this->f->otherCourseId]);
        $this->f->env->eventBridge->published = [];
        $tasksBefore = $this->f->env->scheduler->tasks;
        $intentsBefore = $this->f->env->database->count(Query::from('notification_queue'));
        try {
            $this->sync([$this->f->courseId, $this->f->otherCourseId]);
            self::fail('The archived course must refuse new access.');
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException) {
            self::assertCount(0, $this->grants());
            self::assertSame([], $this->f->env->eventBridge->published);
            self::assertSame($tasksBefore, $this->f->env->scheduler->tasks, 'Rolled-back access must not schedule notifications.');
            self::assertSame($intentsBefore, $this->f->env->database->count(Query::from('notification_queue')));
            self::assertSame(0, $this->f->env->database->count(Query::from('shop_access_links')));
        }
    }

    /** @param list<int> $courses */
    private function sync(
        array $courses,
        bool $active = true,
        string $objectId = '1',
        string $shop = 'woocommerce',
        string $type = 'order',
        int $userId = 60,
        ?string $expiry = null,
        ?Principal $actor = null,
    ): void {
        $this->f->kernel->syncShopAccess->execute(
            $actor ?? $this->f->manager(),
            $shop,
            $type,
            $objectId,
            $userId,
            $courses,
            $active,
            $expiry,
        );
    }

    /** @return list<array<string, mixed>> */
    private function grants(): array
    {
        return $this->f->env->database->selectAll(Query::from('entitlements')->where('user_id', '=', 60));
    }

    /** @return array<string, mixed> */
    private function enrolment(int $courseId, int $userId = 60): array
    {
        $query = Query::from('enrolments')->where('user_id', '=', $userId)->where('course_id', '=', $courseId);
        return $this->f->env->database->selectOne($query) ?? [];
    }
}
