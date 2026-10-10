<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\Handler\Notification\DeliveryFeedbackHandler;
use Shondalai\VidyaLMS\Core\Provider\Notification\Feedback\Rfc5965FeedbackMapper;

/**
 * The learning-flow routes, over the same kernel an adapter mounts.
 *
 * Three answers per route and no more: the shape a caller gets, the refusal a
 * guest gets, and the refusal somebody with no business on the course gets.
 */
final class LearningFlowRoutesTest extends TestCase
{
    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function managerRoutes(): array
    {
        return [
            'notification catalogue' => [
                'GET',
                '/manage/notifications/catalogue',
                ['triggers', 'audiences', 'channels', 'categories', 'schedule_kinds', 'anchors'],
            ],
            'notification rules' => ['GET', '/manage/notifications/rules', ['items', 'total', 'page', 'per_page']],
            'notification queue' => ['GET', '/manage/notifications/queue', ['items', 'total', 'counts_by_state']],
            'notification templates' => ['GET', '/manage/notifications/templates', ['items', 'total']],
            'notification policies' => ['GET', '/manage/notifications/policies', ['cells']],
            'notification suppressions' => ['GET', '/manage/notifications/suppressions', ['items', 'total']],
            'notification consents' => ['GET', '/manage/notifications/consents', ['items', 'total']],
            'provider settings' => [
                'GET',
                '/manage/providers/NotificationPolicy',
                ['registry_key', 'scope_type', 'providers'],
            ],
        ];
    }

    /**
     * @param list<string> $members
     */
    #[DataProvider('managerRoutes')]
    public function testAManagerIsServedTheDeclaredShape(string $method, string $path, array $members): void
    {
        $response = $this->fixture->call($method, $path, $this->fixture->manager());

        self::assertSame(200, $response->status, $path);
        self::assertNotNull($response->data);

        foreach ($members as $member) {
            self::assertArrayHasKey($member, $response->data, $path . ' is missing ' . $member);
        }
    }

    /**
     * @param list<string> $members
     */
    #[DataProvider('managerRoutes')]
    public function testAGuestIsUnauthorized(string $method, string $path, array $members): void
    {
        self::assertSame(401, $this->fixture->call($method, $path, Principal::guest())->status, $path);
    }

    /**
     * @param list<string> $members
     */
    #[DataProvider('managerRoutes')]
    public function testALearnerIsForbidden(string $method, string $path, array $members): void
    {
        self::assertSame(403, $this->fixture->call($method, $path, $this->fixture->learner())->status, $path);
    }

    public function testTheConditionPaletteDeclaresItsOrderAndItsSurfaces(): void
    {
        $response = $this->fixture->call('GET', '/manage/availability/conditions', $this->fixture->teacher());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertCount(5, $response->data['conditions']);
        // Six anchors: the five that answer an instant that already exists,
        // plus the relative anchor R6 registered because none of the five
        // applied an offset and the fan-out applied none of its own, so "wait
        // three days after enrolment" resolved to the enrolment instant and
        // the past-anchor rule fired it immediately.
        self::assertCount(6, $response->data['anchors']);
        self::assertNotSame([], $response->data['presets']);

        $keys = array_column($response->data['conditions'], 'key');
        self::assertContains('date', $keys);
        self::assertContains('enrolment_source', $keys);

        foreach ($response->data['conditions'] as $condition) {
            self::assertArrayHasKey('group', $condition);
            self::assertArrayHasKey('order', $condition);
            self::assertArrayHasKey('params_schema', $condition);
            self::assertNotSame([], $condition['applicability']);
        }
    }

    public function testThePaletteNarrowsToOneSurface(): void
    {
        $response = $this->fixture->call(
            'GET',
            '/manage/availability/conditions',
            $this->fixture->teacher(),
            [],
            ['surface' => 'enrolment_gating'],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);

        // The completion condition declares no enrolment surface, so it is
        // absent rather than offered and refused at save.
        self::assertNotContains('completion', array_column($response->data['conditions'], 'key'));
    }

    public function testThePreviewAnswersLeafByLeafForANamedLearner(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/availability/preview',
            $this->fixture->teacher(),
            [
                'user_id' => ManagementFixture::LEARNER_ID,
                'course_id' => $this->fixture->courseId,
                'subject_type' => 'lesson',
                'subject_id' => $this->fixture->lessonId,
                'tree' => [
                    'op' => 'and',
                    'c' => [[
                        'type' => 'date',
                        'payload' => ['_v' => 1, 'from' => '2030-01-01T00:00:00+00:00', 'tz' => 'UTC'],
                    ]],
                ],
            ],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame('blocked', $response->data['outcome']['state']);
        // The author-facing outcome carries what a learner route never does.
        self::assertArrayHasKey('exhausted', $response->data['outcome']);
        self::assertArrayHasKey('counts_towards_completion', $response->data['outcome']);
        self::assertCount(1, $response->data['leaves']);
        self::assertFalse($response->data['leaves'][0]['passed']);
        self::assertSame('c.0', $response->data['leaves'][0]['path']);
    }

    public function testTheGrantListPublishesItsScopeOrder(): void
    {
        $response = $this->fixture->call(
            'GET',
            '/manage/courses/' . $this->fixture->courseId . '/availability/grants',
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['cohort', 'group', 'course', 'site'], $response->data['scope_order']);
    }

    public function testAGrantWithAnUnknownSubjectTypeIsRefused(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/courses/' . $this->fixture->courseId . '/availability/grants',
            $this->fixture->teacher(),
            [
                'scope_type' => 'course',
                'scope_id' => $this->fixture->courseId,
                'subject_type' => 'badger',
                'subject_id' => 1,
                'user_id' => ManagementFixture::LEARNER_ID,
                'mode' => 'grant',
            ],
        );

        self::assertSame(422, $response->status);
    }

    /**
     * A machine on the far side of a mail provider, refused without being
     * told whether the channel exists.
     */
    public function testTheFeedbackRouteRefusesWithoutTheSharedSecret(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/notifications/feedback/email',
            Principal::guest(),
            [DeliveryFeedbackHandler::RAW_BODY => 'Feedback-Type: abuse', DeliveryFeedbackHandler::CONTENT_TYPE => 'text/plain'],
        );

        self::assertSame(401, $response->status);
    }

    public function testTheFeedbackRouteMapsAnUncorrelatedAbuseReportWithoutSuppressingTheAddress(): void
    {
        $this->fixture->env->secrets->put('env:VIDYALMS_SECRET_VIDYALMS_EMAIL_FEEDBACK_SECRET', 'shhh');
        $this->fixture->kernel->providerSettings->save(
            'Notification',
            'email',
            'site',
            0,
            [],
            null,
            'env:VIDYALMS_SECRET_VIDYALMS_EMAIL_FEEDBACK_SECRET',
        );

        $report = "Feedback-Type: abuse\nUser-Agent: Example/1\nVersion: 1\nOriginal-Rcpt-To: lena@example.test\n";

        $response = $this->fixture->call(
            'POST',
            '/notifications/feedback/email',
            Principal::guest(),
            [
                DeliveryFeedbackHandler::RAW_BODY => $report,
                DeliveryFeedbackHandler::CONTENT_TYPE => 'message/feedback-report',
            ],
            headers: [DeliveryFeedbackHandler::SECRET_HEADER => 'shhh'],
        );

        self::assertSame(202, $response->status);
        self::assertNotNull($response->data);
        self::assertTrue($response->data['mapped']);
        self::assertSame(Rfc5965FeedbackMapper::KEY, $response->data['mapper_key']);
        self::assertSame('complaint', $response->data['type']);
        self::assertFalse($response->data['suppressed']);
    }

    /**
     * A payload no mapper claims is logged unparsed and writes no
     * suppression, which is the published miss of that registry rather than
     * an error.
     */
    public function testAnUnclaimedPayloadIsAcceptedAndWritesNothing(): void
    {
        $this->fixture->env->secrets->put('env:VIDYALMS_SECRET_VIDYALMS_EMAIL_FEEDBACK_SECRET', 'shhh');
        $this->fixture->kernel->providerSettings->save(
            'Notification',
            'email',
            'site',
            0,
            [],
            null,
            'env:VIDYALMS_SECRET_VIDYALMS_EMAIL_FEEDBACK_SECRET',
        );

        $response = $this->fixture->call(
            'POST',
            '/notifications/feedback/email',
            Principal::guest(),
            [
                'event' => 'delivered',
            ],
            headers: [DeliveryFeedbackHandler::SECRET_HEADER => 'shhh'],
        );

        self::assertSame(202, $response->status);
        self::assertNotNull($response->data);
        self::assertFalse($response->data['mapped']);
        self::assertFalse($response->data['suppressed']);
    }
}
