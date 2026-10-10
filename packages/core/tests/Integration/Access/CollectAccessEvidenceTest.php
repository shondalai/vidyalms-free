<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Access\CollectAccessEvidence;
use Shondalai\VidyaLMS\Core\Application\Commerce\SourceRef;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;

/** Learning facts remain available for disputes handled by the external shop. */
final class CollectAccessEvidenceTest extends TestCase
{
    private AccessFixture $fixture;
    private int $courseId;
    private int $entitlementId;

    protected function setUp(): void
    {
        $this->fixture = AccessFixture::create();
        $this->fixture->createUser(6001);
        $this->fixture->createUser(6002);
        $this->fixture->grantCapability(6001);
        $this->fixture->grantCapability(6001, Capability::VIEW_REPORTS);
        $this->courseId = $this->fixture->createCourse(['price_mode' => 'paid']);
        $entitlement = $this->fixture->grantEntitlement->execute(
            Principal::user(6001),
            6002,
            $this->courseId,
            Entitlement::SOURCE_PURCHASE,
            SourceRef::compose('wc', 'order', 91, $this->courseId),
        );
        $this->entitlementId = $entitlement->id();
    }

    public function testTheFactsRetainTheShopSourceReferenceAndEnrolment(): void
    {
        $facts = $this->collect(6002);

        self::assertSame(6002, $facts['user_id']);
        self::assertSame($this->courseId, $facts['course_id']);
        self::assertSame($this->entitlementId, (int) $facts['entitlements'][0]['id']);
        self::assertSame(
            SourceRef::compose('wc', 'order', 91, $this->courseId),
            $facts['entitlements'][0]['source_ref'],
        );
        self::assertNotNull($facts['enrolment']);
        self::assertSame([], $facts['warnings']);
    }

    public function testPurchaseIdentifiersAreNotExportedAsPersonalNetworkData(): void
    {
        $this->fixture->env->database->update('entitlements', [
            'purchase_ip_hash' => hash('sha256', '203.0.113.7'),
            'purchase_country' => 'IE',
        ], ['id' => $this->entitlementId]);

        $facts = $this->collect(6002);

        self::assertArrayNotHasKey('purchase_ip_hash', $facts['entitlements'][0]);
        self::assertStringNotContainsString('203.0.113.7', json_encode($facts, JSON_THROW_ON_ERROR));
    }

    public function testTheTimelineContainsOnlyThisLearnersActivityInThisCourse(): void
    {
        foreach ([[6002, $this->courseId], [6001, $this->courseId], [6002, $this->courseId + 100]] as [$user, $course]) {
            $this->fixture->env->database->insert('activity_log', [
                'user_id' => $user,
                'course_id' => $course,
                'action' => 'lesson_viewed',
                'occurred_at' => '2026-01-22 10:00:00',
                'created_at' => '2026-01-22 10:00:00',
                'updated_at' => '2026-01-22 10:00:00',
            ]);
        }

        $facts = $this->collect(6002);

        self::assertCount(1, $facts['timeline']);
        self::assertSame('lesson_viewed', $facts['timeline'][0]['action']);
    }

    public function testMissingLearningRecordsProduceExplicitWarnings(): void
    {
        $facts = $this->collect(6999);

        self::assertSame([], $facts['entitlements']);
        self::assertNull($facts['enrolment']);
        self::assertContains('no_entitlement_on_record', $facts['warnings']);
        self::assertContains('no_enrolment_on_record', $facts['warnings']);
    }

    /** @return array<string, mixed> */
    private function collect(int $learner): array
    {
        return (new CollectAccessEvidence(
            $this->fixture->env->database,
            $this->fixture->courseAccess,
            $this->fixture->env->clock,
        ))->execute(Principal::user(6001), $learner, $this->courseId);
    }
}
