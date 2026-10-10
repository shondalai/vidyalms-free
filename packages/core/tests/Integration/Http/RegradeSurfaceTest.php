<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * The regrade routes: what they answer, and who may run one.
 */
final class RegradeSurfaceTest extends TestCase
{
    private ManagementFixture $fixture;
    private int $attemptId = 0;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->seedClosedAttempt();
    }

    public function testAQuizRegradeAnswersExactlyWhatMoved(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/quizzes/' . $this->fixture->quizId . '/regrade',
            $this->fixture->teacher(),
            ['policy' => 'full_credit', 'reason' => 'The stem was ambiguous.'],
        );

        self::assertSame(200, $response->status, json_encode($response->data, JSON_THROW_ON_ERROR));
        self::assertNotNull($response->data);
        self::assertSame([
            'policy',
            'dry_run',
            'attempts_examined',
            'attempts_changed',
            'grades_appended',
            'questions_regraded',
            'items',
            'warnings',
        ], array_keys($response->data));

        self::assertSame('full_credit', $response->data['policy']);
        self::assertFalse($response->data['dry_run']);
        self::assertSame(1, $response->data['attempts_examined']);
        self::assertSame(1, $response->data['attempts_changed']);
        self::assertSame(1, $response->data['grades_appended']);

        self::assertSame([
            'attempt_id',
            'user_id',
            'attempt_number',
            'score_before',
            'score_after',
            'percentage_before',
            'percentage_after',
            'passed_before',
            'passed_after',
            'questions_changed',
        ], array_keys($response->data['items'][0]));
        self::assertSame(5.0, $response->data['items'][0]['score_after']);
    }

    public function testADryRunReportsTheSameAnswerAndWritesNothing(): void
    {
        $dry = $this->fixture->call(
            'POST',
            '/manage/attempts/' . $this->attemptId . '/regrade',
            $this->fixture->teacher(),
            ['policy' => 'full_credit', 'dry_run' => true],
        );

        self::assertSame(200, $dry->status);
        self::assertNotNull($dry->data);
        self::assertTrue($dry->data['dry_run']);
        self::assertSame(1, $dry->data['attempts_changed']);
        self::assertSame(0, $dry->data['grades_appended']);

        $attempt = $this->fixture->call('GET', '/attempts/' . $this->attemptId, $this->fixture->learner());
        self::assertSame(0.0, $attempt->data['attempt']['score'] ?? null);
    }

    public function testAnUnknownPolicyIsRefused(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/attempts/' . $this->attemptId . '/regrade',
            $this->fixture->teacher(),
            ['policy' => 'be_generous'],
        );

        self::assertSame(422, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('policy', $response->data['errors']);
    }

    public function testAReasonLongerThanTheContractAllowsIsRefused(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/attempts/' . $this->attemptId . '/regrade',
            $this->fixture->teacher(),
            ['policy' => 'full_credit', 'reason' => str_repeat('x', 501)],
        );

        self::assertSame(422, $response->status);
    }

    public function testOnlySomeoneWhoMayGradeTheCourseMayRegrade(): void
    {
        $path = '/manage/attempts/' . $this->attemptId . '/regrade';
        $body = ['policy' => 'full_credit'];

        self::assertSame(401, $this->fixture->call('POST', $path, Principal::guest(), $body)->status);
        self::assertSame(403, $this->fixture->call('POST', $path, $this->fixture->learner(), $body)->status);
        self::assertSame(403, $this->fixture->call('POST', $path, $this->fixture->outsider(), $body)->status);
        self::assertSame(404, $this->fixture->call(
            'POST',
            '/manage/attempts/9999/regrade',
            $this->fixture->teacher(),
            $body,
        )->status);
    }

    private function seedClosedAttempt(): void
    {
        $learner = $this->fixture->learner();

        $attempt = $this->fixture->call('POST', '/quizzes/' . $this->fixture->quizId . '/attempts', $learner);
        self::assertSame(201, $attempt->status, json_encode($attempt->data, JSON_THROW_ON_ERROR));
        $this->attemptId = (int) ($attempt->data['id'] ?? 0);

        $this->fixture->call('PUT', '/attempts/' . $this->attemptId . '/answers', $learner, [
            'answers' => [(string) $this->fixture->questionId => 'Partly oxidised.'],
        ]);
        $this->fixture->env->clock->advance('PT1M');
        $this->fixture->call('POST', '/attempts/' . $this->attemptId . '/submit', $learner);

        // The fixture's only question is a short answer with no accepted
        // list, so it waits for a human; a grade closes the attempt and
        // leaves a regrade something to move.
        $graded = $this->fixture->call(
            'POST',
            '/attempts/' . $this->attemptId . '/answers/' . $this->fixture->questionId . '/grade',
            $this->fixture->teacher(),
            ['points_awarded' => 0, 'feedback' => 'Not quite.'],
        );
        self::assertSame(200, $graded->status, json_encode($graded->data, JSON_THROW_ON_ERROR));
    }
}
