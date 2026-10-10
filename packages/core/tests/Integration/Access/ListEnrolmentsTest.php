<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Access;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Access\Entitlement;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;

final class ListEnrolmentsTest extends TestCase
{
    private AccessFixture $fixture;

    private Principal $admin;

    protected function setUp(): void
    {
        $this->fixture = AccessFixture::create();
        $this->fixture->grantCapability(10);
        $this->admin = Principal::user(10);
    }

    public function testListMyEnrolmentsJoinsCourseTitleAndSlug(): void
    {
        $courseId = $this->fixture->createCourse(['title' => 'Joomla Mastery', 'slug' => 'joomla-mastery']);
        $this->fixture->selfEnrol->execute(Principal::user(42), $courseId);

        $rows = $this->fixture->listMyEnrolments->execute(Principal::user(42));

        self::assertCount(1, $rows);
        self::assertSame($courseId, $rows[0]['course_id']);
        self::assertSame('Joomla Mastery', $rows[0]['course_title']);
        self::assertSame('joomla-mastery', $rows[0]['course_slug']);
        self::assertSame('active', $rows[0]['status']);
        self::assertSame(42, $rows[0]['user_id']);
    }

    public function testListMyEnrolmentsSeesOnlyTheCallersRows(): void
    {
        $courseId = $this->fixture->createCourse();
        $this->fixture->selfEnrol->execute(Principal::user(42), $courseId);
        $this->fixture->selfEnrol->execute(Principal::user(43), $courseId);

        $rows = $this->fixture->listMyEnrolments->execute(Principal::user(43));

        self::assertCount(1, $rows);
        self::assertSame(43, $rows[0]['user_id']);
    }

    public function testListMyEnrolmentsDeniesGuests(): void
    {
        $this->expectException(NotAuthorizedException::class);
        $this->fixture->listMyEnrolments->execute(Principal::guest());
    }

    public function testListForCourseRequiresViewReports(): void
    {
        $courseId = $this->fixture->createCourse();

        $this->expectException(NotAuthorizedException::class);
        $this->fixture->listEnrolmentsForCourse->execute(Principal::user(42), $courseId);
    }

    public function testListForCoursePagesThroughTheRoster(): void
    {
        $courseId = $this->fixture->createCourse();
        $otherCourseId = $this->fixture->createCourse();
        $this->fixture->grantCapability(10, Capability::VIEW_REPORTS);

        foreach ([42, 43, 44] as $userId) {
            $this->fixture->createUser($userId);
            $this->fixture->grantEntitlement->execute($this->admin, $userId, $courseId, Entitlement::SOURCE_MANUAL);
        }

        $this->fixture->createUser(45);
        $this->fixture->grantEntitlement->execute($this->admin, 45, $otherCourseId, Entitlement::SOURCE_MANUAL);

        $firstPage = $this->fixture->listEnrolmentsForCourse->execute($this->admin, $courseId, 2, 0);
        $secondPage = $this->fixture->listEnrolmentsForCourse->execute($this->admin, $courseId, 2, 2);

        self::assertSame(3, $firstPage['total']);
        self::assertCount(2, $firstPage['items']);
        self::assertCount(1, $secondPage['items']);
        self::assertSame(2, $firstPage['limit']);
        self::assertSame(2, $secondPage['offset']);

        $seen = array_merge(
            array_column($firstPage['items'], 'user_id'),
            array_column($secondPage['items'], 'user_id'),
        );
        sort($seen);
        self::assertSame([42, 43, 44], $seen);
    }
}
