<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Platform;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * The two site-wide lists now filter, sort and page at the database, and
 * three of the things they do are only obviously right when a test says so:
 * "never expires" sorts last in both directions rather than reading as the
 * largest date, a certificate's status is judged against the clock rather
 * than stored, and a search on a person is resolved through the users port
 * and then applied as an ordinary filter.
 */
final class AdminListDatabasePagingTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testNeverExpiresSortsLastInBothDirections(): void
    {
        $this->fixture->env->database->update(
            'entitlements',
            ['expires_at' => null],
            ['id' => $this->fixture->entitlementId],
        );

        $expiring = $this->enrol(60, 'Ellie Expiring', '2026-06-01 00:00:00');
        $soon = $this->enrol(61, 'Sam Soon', '2026-02-01 00:00:00');

        foreach (['asc', 'desc'] as $order) {
            $page = $this->fixture->call('GET', '/admin/enrolments', $this->fixture->manager(), [], [
                'sort' => 'expires_at',
                'order' => $order,
            ]);

            self::assertNotNull($page->data);
            $ids = array_map(static fn (array $item): int => $item['user']['id'], $page->data['items']);

            self::assertSame(
                ManagementFixture::LEARNER_ID,
                $ids[\count($ids) - 1],
                sprintf('the entitlement that never expires sorts last %s', $order),
            );
        }

        $ascending = $this->fixture->call('GET', '/admin/enrolments', $this->fixture->manager(), [], [
            'sort' => 'expires_at',
            'order' => 'asc',
        ]);
        self::assertNotNull($ascending->data);
        $ordered = array_map(static fn (array $item): int => $item['user']['id'], $ascending->data['items']);
        self::assertSame([$soon, $expiring, ManagementFixture::LEARNER_ID], $ordered);
    }

    public function testACertificateStatusIsJudgedAgainstTheClockAndNotStored(): void
    {
        $manager = $this->fixture->manager();
        $this->issueCertificate('EXPIRESOON', '2026-02-01 00:00:00');
        $this->issueCertificate('NEVEREXPIRE', null);

        $valid = $this->fixture->call('GET', '/admin/certificates', $manager, [], ['status' => 'valid']);
        self::assertNotNull($valid->data);
        self::assertSame(2, $valid->data['total'], 'a null expiry is valid, not expired');

        $this->fixture->env->clock->advance('P60D');

        $expired = $this->fixture->call('GET', '/admin/certificates', $manager, [], ['status' => 'expired']);
        self::assertNotNull($expired->data);
        self::assertSame(1, $expired->data['total']);
        self::assertSame('EXPIRESOON', $expired->data['items'][0]['code']);

        $stillValid = $this->fixture->call('GET', '/admin/certificates', $manager, [], ['status' => 'valid']);
        self::assertNotNull($stillValid->data);
        self::assertSame(1, $stillValid->data['total']);
        self::assertSame('NEVEREXPIRE', $stillValid->data['items'][0]['code']);
    }

    public function testEveryDeclaredCertificateStatusUsesThePublishedPrecedence(): void
    {
        $manager = $this->fixture->manager();
        $now = $this->fixture->env->clock->now();
        $past = $now->modify('-1 day')->format('Y-m-d H:i:s');
        $future = $now->modify('+1 day')->format('Y-m-d H:i:s');

        $this->issueCertificate('ACTIVEWINDOW', null);
        $this->issueCertificate('EXPIREDWINDOW', $past);
        $this->issueCertificate('FUTUREFIRST', $past, ['valid_from' => $future]);
        $this->issueCertificate('SUSPENDEDROW', null, ['status' => 'suspended']);
        $this->issueCertificate('REVOKEDROW', null, ['status' => 'revoked']);
        $this->issueCertificate('SUPERSEDEDROW', null, ['status' => 'superseded']);

        foreach (
            [
                'active' => 'ACTIVEWINDOW',
                'valid' => 'ACTIVEWINDOW',
                'expired' => 'EXPIREDWINDOW',
                'not_yet_valid' => 'FUTUREFIRST',
                'suspended' => 'SUSPENDEDROW',
                'revoked' => 'REVOKEDROW',
                'superseded' => 'SUPERSEDEDROW',
            ] as $status => $code
        ) {
            $response = $this->fixture->call('GET', '/admin/certificates', $manager, [], ['status' => $status]);
            self::assertSame(200, $response->status, $status);
            self::assertNotNull($response->data);
            $codes = array_column($response->data['items'], 'code');
            self::assertContains($code, $codes, $status);
            $matching = array_values(array_filter(
                $response->data['items'],
                static fn (array $item): bool => $item['code'] === $code,
            ));
            self::assertSame($status === 'valid' ? 'active' : $status, $matching[0]['status'], $status);
        }

        $expired = $this->fixture->call('GET', '/admin/certificates', $manager, [], ['status' => 'expired']);
        self::assertNotNull($expired->data);
        self::assertNotContains(
            'FUTUREFIRST',
            array_column($expired->data['items'], 'code'),
            'not yet valid precedes expired when both date predicates are true',
        );
    }

    public function testCertificateCourseAndTemplateFiltersReachTheRepositoryTogether(): void
    {
        $manager = $this->fixture->manager();
        $this->issueCertificate('FILTERTARGET', null, [
            'course_id' => $this->fixture->otherCourseId,
            'template_id' => 77,
        ]);
        $this->issueCertificate('COURSEONLY', null, [
            'course_id' => $this->fixture->otherCourseId,
            'template_id' => 78,
        ]);
        $this->issueCertificate('TEMPLATEONLY', null, ['template_id' => 77]);

        $byCourse = $this->fixture->call('GET', '/admin/certificates', $manager, [], [
            'course_id' => (string) $this->fixture->otherCourseId,
        ]);
        self::assertNotNull($byCourse->data);
        self::assertSame(2, $byCourse->data['total']);
        foreach ($byCourse->data['items'] as $item) {
            self::assertSame($this->fixture->otherCourseId, $item['course_id']);
        }

        $byTemplate = $this->fixture->call('GET', '/admin/certificates', $manager, [], ['template_id' => '77']);
        self::assertNotNull($byTemplate->data);
        self::assertSame(2, $byTemplate->data['total']);
        foreach ($byTemplate->data['items'] as $item) {
            self::assertSame(77, $item['template_id']);
        }

        $combined = $this->fixture->call('GET', '/admin/certificates', $manager, [], [
            'course_id' => (string) $this->fixture->otherCourseId,
            'template_id' => '77',
        ]);
        self::assertNotNull($combined->data);
        self::assertSame(1, $combined->data['total']);
        self::assertSame('FILTERTARGET', $combined->data['items'][0]['code']);
    }

    public function testInvalidCertificateFiltersAnswerValidationProblems(): void
    {
        foreach (
            [
                'status' => ['status' => 'unknown'],
                'course id' => ['course_id' => '0'],
                'template id' => ['template_id' => '0'],
            ] as $case => $query
        ) {
            $response = $this->fixture->call(
                'GET',
                '/admin/certificates',
                $this->fixture->manager(),
                [],
                $query,
            );
            self::assertSame(422, $response->status, $case);
        }
    }

    public function testTheCertificateSearchMatchesACodeOrAPerson(): void
    {
        $manager = $this->fixture->manager();
        $this->issueCertificate('OOLONG42', null);

        $byCode = $this->fixture->call('GET', '/admin/certificates', $manager, [], ['q' => 'oolong42']);
        self::assertNotNull($byCode->data);
        self::assertSame(1, $byCode->data['total'], 'a code matches whatever case it is typed in');

        $byPerson = $this->fixture->call('GET', '/admin/certificates', $manager, [], ['q' => 'Lena']);
        self::assertNotNull($byPerson->data);
        self::assertSame(1, $byPerson->data['total']);

        $nobody = $this->fixture->call('GET', '/admin/certificates', $manager, [], ['q' => 'nobody-at-all']);
        self::assertNotNull($nobody->data);
        self::assertSame(0, $nobody->data['total']);
    }

    public function testCertificatePersonSearchIsNotCappedLikeTheAccountPicker(): void
    {
        $lastUserId = 0;

        for ($index = 1; $index <= 505; ++$index) {
            $lastUserId = 10000 + $index;
            $this->fixture->env->users->add(
                $lastUserId,
                'Enterprise Match ' . $index,
                sprintf('enterprise-match-%d@example.test', $index),
            );
        }

        $this->issueCertificate('UNCAPPEDSEARCH', null, [
            'user_id' => $lastUserId,
            'enrolment_id' => 90000,
        ]);

        $response = $this->fixture->call(
            'GET',
            '/admin/certificates',
            $this->fixture->manager(),
            [],
            ['q' => 'Enterprise Match'],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(1, $response->data['total']);
        self::assertSame('UNCAPPEDSEARCH', $response->data['items'][0]['code']);
    }

    private function enrol(int $userId, string $name, ?string $expiresAt): int
    {
        $this->fixture->env->users->add($userId, $name, sprintf('user%d@example.test', $userId));
        $now = $this->fixture->env->clock->now()->format('Y-m-d H:i:s');

        $entitlementId = $this->fixture->env->database->insert('entitlements', [
            'user_id' => $userId,
            'course_id' => $this->fixture->courseId,
            'source' => 'manual',
            'status' => 'active',
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->fixture->env->database->insert('enrolments', [
            'user_id' => $userId,
            'course_id' => $this->fixture->courseId,
            'entitlement_id' => $entitlementId,
            'status' => 'active',
            'enrolled_at' => $now,
            'progress_percent' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $userId;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function issueCertificate(string $code, ?string $expiresAt, array $overrides = []): int
    {
        $now = $this->fixture->env->clock->now()->format('Y-m-d H:i:s');
        $enrolment = $this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('course_id', '=', $this->fixture->courseId)
                ->where('user_id', '=', ManagementFixture::LEARNER_ID),
        );

        self::assertNotNull($enrolment);

        return $this->fixture->env->database->insert('certificates', array_replace([
            'user_id' => ManagementFixture::LEARNER_ID,
            'course_id' => $this->fixture->courseId,
            'enrolment_id' => (int) $enrolment['id'] + \strlen($code),
            'template_id' => 1,
            'code' => $code,
            'issued_at' => $now,
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    /**
     * A guard on the fixture the assertions above lean on.
     */
    public function testTheManagerCanReachBothLists(): void
    {
        $manager = Principal::user(ManagementFixture::MANAGER_ID);

        self::assertSame(200, $this->fixture->call('GET', '/admin/enrolments', $manager)->status);
        self::assertSame(200, $this->fixture->call('GET', '/admin/certificates', $manager)->status);
    }
}
