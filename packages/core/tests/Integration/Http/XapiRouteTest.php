<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiVocabulary;

/**
 * GET /manage/attempts/{id}/xapi as a client observes it.
 *
 * The route is free and ungated for the reason every management route is:
 * a site owner who has to prove what their LMS emits cannot be asked to buy
 * a package first, and a conformance run needs the same bytes a forwarder
 * would post.
 */
final class XapiRouteTest extends TestCase
{
    private ManagementFixture $fixture;
    private int $attemptId = 0;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();

        $started = $this->fixture->call(
            'POST',
            '/quizzes/' . $this->fixture->quizId . '/attempts',
            $this->fixture->learner(),
        );

        self::assertSame(201, $started->status, (string) json_encode($started->data));
        self::assertNotNull($started->data);
        $this->attemptId = (int) $started->data['id'];

        $this->fixture->call('PUT', '/attempts/' . $this->attemptId . '/answers', $this->fixture->learner(), [
            'answers' => [(string) $this->fixture->questionId => 'A roasted oolong.'],
        ]);
        $this->fixture->call('POST', '/attempts/' . $this->attemptId . '/submit', $this->fixture->learner());
    }

    public function testTheRouteAnswersTheStatementsOfOneAttempt(): void
    {
        $response = $this->fixture->call(
            'GET',
            '/manage/attempts/' . $this->attemptId . '/xapi',
            $this->fixture->teacher(),
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(
            ['attempt_id', 'registration', 'statements', 'published'],
            array_keys($response->data),
        );
        self::assertSame(0, $response->data['published'], 'a read publishes nothing');
        self::assertSame($this->attemptId, $response->data['attempt_id']);

        $statements = $response->data['statements'];
        self::assertIsArray($statements);
        self::assertNotSame([], $statements);

        $first = $statements[0];
        self::assertSame(
            ['id', 'actor', 'verb', 'object', 'context', 'timestamp'],
            array_keys($first),
            'the attempted statement has no result',
        );
        self::assertSame(XapiVocabulary::VERB_ATTEMPTED, $first['verb']['id']);
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/',
            (string) $first['timestamp'],
        );
    }

    public function testAGuestIsRefused(): void
    {
        $response = $this->fixture->call(
            'GET',
            '/manage/attempts/' . $this->attemptId . '/xapi',
            Principal::guest(),
        );

        self::assertSame(401, $response->status);
    }

    public function testAnInstructorOfAnotherCourseIsRefused(): void
    {
        $response = $this->fixture->call(
            'GET',
            '/manage/attempts/' . $this->attemptId . '/xapi',
            $this->fixture->outsider(),
        );

        self::assertSame(403, $response->status);
    }

    public function testAnUnknownAttemptIs404(): void
    {
        $response = $this->fixture->call('GET', '/manage/attempts/999999/xapi', $this->fixture->teacher());

        self::assertSame(404, $response->status);
    }
}
