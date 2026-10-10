<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Availability\DateCondition;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;
use Shondalai\VidyaLMS\Core\Tests\Integration\Http\ManagementFixture;

/**
 * A free-preview lesson is one its author chose to show to people who have
 * not enrolled, so a visitor without an enrolment is shown the lesson and
 * not its placeholder. What a preview never opens is the learning record:
 * no heartbeat, no completion, no attempt and no submission is accepted
 * without an enrolment.
 *
 * The defect this pins: the preview was served through the no-access
 * presentation, so a guest opening a free-preview video lesson got a 200
 * with a null url and the player said the video could not be embedded, and
 * a free-preview reading lesson said there was nothing to read.
 *
 * Every case runs through the route, because the route is what the player
 * calls and what both hosts serve.
 */
final class FreePreviewTest extends TestCase
{
    /** Signed in, no capability, no enrolment anywhere. */
    private const VISITOR_ID = 77;

    private const VIDEO_URL = 'https://cdn.example.test/intro.mp4';
    private const CAPTION_URL = 'https://cdn.example.test/intro.vtt';

    private ManagementFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
    }

    public function testAGuestPreviewsAVideoLessonWithItsPlayableUrls(): void
    {
        $lessonId = $this->previewLesson('video', [
            'provider' => 'url',
            'url' => self::VIDEO_URL,
            'caption_url' => self::CAPTION_URL,
        ]);

        $view = $this->lessonAs(Principal::guest(), $lessonId);

        self::assertSame(self::VIDEO_URL, $view['content']['url'] ?? null, 'the video is the preview');
        self::assertSame(self::CAPTION_URL, $view['content']['caption_url'] ?? null);
        self::assertSame('url', $view['content']['provider'] ?? null);
        self::assertTrue($view['content_available']);
        self::assertTrue($view['free_preview']);
        self::assertNull($view['progress']);
    }

    public function testAGuestPreviewsAReadingLessonWithItsBody(): void
    {
        $lessonId = $this->previewLesson('reading', ['body' => '<p>Warm the pot first.</p>']);

        $view = $this->lessonAs(Principal::guest(), $lessonId);

        self::assertSame('<p>Warm the pot first.</p>', $view['content']['body'] ?? null);
        self::assertTrue($view['content_available']);
    }

    /**
     * Signing in without enrolling changes nothing about what a preview
     * shows: the visitor is the same audience as the guest.
     */
    public function testASignedInVisitorWithoutAnEnrolmentIsShownTheSamePreviewAsAGuest(): void
    {
        $lessonId = $this->previewLesson('video', [
            'provider' => 'url',
            'url' => self::VIDEO_URL,
            'caption_url' => self::CAPTION_URL,
        ]);

        $guest = $this->lessonAs(Principal::guest(), $lessonId);
        $visitor = $this->lessonAs(Principal::user(self::VISITOR_ID), $lessonId);

        self::assertSame($guest['content'], $visitor['content']);
        self::assertSame(self::VIDEO_URL, $visitor['content']['url'] ?? null);
        self::assertNull($visitor['progress']);
    }

    /**
     * A previewed resource is downloadable, through the same signed and
     * expiring URL an enrolled learner gets. The raw storage path is still
     * never in a response.
     */
    public function testAResourcePreviewCarriesSignedUrlsAndNeverThePath(): void
    {
        $lessonId = $this->previewLesson('resource', [
            'files' => [['path' => 'lesson-files/handout.pdf', 'name' => 'Handout', 'size_bytes' => 2048]],
        ]);

        $response = $this->fixture->call('GET', '/lessons/' . $lessonId, Principal::guest());

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame([
            'files' => [[
                'name' => 'Handout',
                'size_bytes' => 2048,
                'url' => $this->fixture->env->storage->signedUrl('lesson-files/handout.pdf', 3600),
            ]],
        ], $response->data['content']);
        self::assertStringNotContainsString('"path"', json_encode($response->data, JSON_THROW_ON_ERROR));
    }

    /**
     * The decision recorded in 13-learning-flow-and-credentials.md: an
     * assignment marked free preview shows its brief, because the author
     * chose to disclose it and nothing gradable is in it, and submitting
     * still needs an active enrolment.
     */
    public function testAnAssignmentPreviewShowsItsInstructionsAndRefusesASubmission(): void
    {
        $lessonId = $this->previewLesson('assignment', ['instructions' => '<p>Describe three oolongs.</p>']);

        self::assertSame(
            '<p>Describe three oolongs.</p>',
            $this->lessonAs(Principal::guest(), $lessonId)['content']['instructions'] ?? null,
        );

        $path = '/assignments/' . $lessonId . '/submissions';
        $body = ['text_response' => 'Roasted, floral, milky.', 'files' => []];

        self::assertSame(401, $this->statusOf($path, Principal::guest(), $body));
        self::assertSame(403, $this->statusOf($path, Principal::user(self::VISITOR_ID), $body));
        self::assertSame(0, $this->fixture->env->database->count(Query::from('submissions')));
    }

    /**
     * The decision recorded in 13-learning-flow-and-credentials.md: a quiz
     * preview is its overview. The questions are served only inside an
     * attempt and an attempt needs an active enrolment, so the start handle
     * is withheld from the content and the start route refuses on its own.
     */
    public function testAQuizPreviewIsItsOverviewAndAnAttemptIsRefused(): void
    {
        $created = $this->fixture->call(
            'POST',
            '/manage/modules/' . $this->fixture->moduleId . '/lessons',
            $this->fixture->teacher(),
            ['title' => 'Preview checkpoint', 'type' => 'quiz', 'free_preview' => true],
        );
        self::assertSame(201, $created->status, (string) json_encode($created->data));
        $lessonId = (int) ($created->data['id'] ?? 0);
        $quizId = (int) ($created->data['quiz']['id'] ?? 0);

        $view = $this->lessonAs(Principal::guest(), $lessonId);

        self::assertSame(['quiz_id' => 0], $view['content']);
        self::assertIsArray($view['quiz']);
        self::assertSame($quizId, $view['quiz']['id']);
        self::assertSame(0, $view['quiz']['attempts_used']);
        self::assertNull($view['quiz']['last_attempt']);

        $path = '/quizzes/' . $quizId . '/attempts';

        self::assertSame(401, $this->statusOf($path, Principal::guest(), []));
        self::assertSame(403, $this->statusOf($path, Principal::user(self::VISITOR_ID), []));
        self::assertSame(0, $this->fixture->env->database->count(Query::from('attempts')->where('quiz_id', '=', $quizId)));
    }

    /**
     * Opening a preview is not learning it. A heartbeat needs an enrolment
     * of the caller's own, so neither a guest nor a visitor can record
     * progress or a completion against a preview lesson, not even by naming
     * somebody else's enrolment.
     */
    public function testAPreviewRecordsNoProgressAndNoCompletion(): void
    {
        $lessonId = $this->previewLesson('reading', ['body' => '<p>Warm the pot first.</p>']);
        $learnerEnrolment = $this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('course_id', '=', $this->fixture->courseId)
                ->where('user_id', '=', ManagementFixture::LEARNER_ID),
        );
        self::assertNotNull($learnerEnrolment);

        $path = '/lessons/' . $lessonId . '/progress';
        $beat = ['enrolment_id' => (int) $learnerEnrolment['id'], 'progress_percent' => 100.0, 'completed' => true];

        self::assertSame(401, $this->statusOf($path, Principal::guest(), $beat));
        self::assertSame(403, $this->statusOf($path, Principal::user(self::VISITOR_ID), $beat));
        self::assertSame(
            0,
            $this->fixture->env->database->count(Query::from('lesson_progress')->where('lesson_id', '=', $lessonId)),
        );
    }

    /**
     * A preview opens a lesson to people without an enrolment; it does not
     * lift a restriction. A preview lesson a date rule keeps closed is
     * withheld exactly as it is for an enrolled learner.
     */
    public function testAPreviewLessonClosedByARestrictionStaysWithheld(): void
    {
        $this->fixture->env->clock->set('2026-04-01 00:00:00');
        $lessonId = $this->previewLesson('reading', ['body' => '<p>Not yet.</p>']);
        $this->gateFrom($lessonId, '2030-01-01T00:00:00+00:00');

        $view = $this->lessonAs(Principal::guest(), $lessonId);

        self::assertSame('blocked', $view['access']['state'] ?? null);
        self::assertNull($view['content']['body'] ?? null);
        self::assertFalse($view['content_available']);
    }

    /**
     * The enrolled learner on a preview lesson is a learner, not a visitor:
     * full content and their own progress row, exactly as on any lesson.
     */
    public function testAnEnrolledLearnerOnAPreviewLessonKeepsTheirProgress(): void
    {
        $lessonId = $this->previewLesson('reading', ['body' => '<p>Warm the pot first.</p>']);
        $enrolment = $this->fixture->env->database->selectOne(
            Query::from('enrolments')
                ->where('course_id', '=', $this->fixture->courseId)
                ->where('user_id', '=', ManagementFixture::LEARNER_ID),
        );
        self::assertNotNull($enrolment);

        $beat = $this->fixture->call(
            'POST',
            '/lessons/' . $lessonId . '/progress',
            $this->fixture->learner(),
            ['enrolment_id' => (int) $enrolment['id'], 'progress_percent' => 40.0, 'seconds_delta' => 15],
            [],
            'beat-learner',
        );
        self::assertSame(204, $beat->status, (string) json_encode($beat->data));

        $view = $this->lessonAs($this->fixture->learner(), $lessonId);

        self::assertSame('<p>Warm the pot first.</p>', $view['content']['body'] ?? null);
        self::assertIsArray($view['progress']);
        self::assertSame('in_progress', $view['progress']['status']);
    }

    /**
     * @param array<string, mixed> $content
     */
    private function previewLesson(string $type, array $content): int
    {
        $response = $this->fixture->call(
            'POST',
            '/manage/modules/' . $this->fixture->moduleId . '/lessons',
            $this->fixture->teacher(),
            ['title' => 'Preview ' . $type, 'type' => $type, 'free_preview' => true, 'content' => $content,
                'duration_seconds' => $type === 'video' ? 60 : null],
        );

        self::assertSame(201, $response->status, (string) json_encode($response->data));

        return (int) ($response->data['id'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function lessonAs(Principal $principal, int $lessonId): array
    {
        $response = $this->fixture->call('GET', '/lessons/' . $lessonId, $principal);

        self::assertSame(200, $response->status, (string) json_encode($response->data));

        return $this->data($response);
    }

    /**
     * A write, with an idempotency key of its own so a refusal is never a
     * replay of an earlier call.
     *
     * @param array<string, mixed> $body
     */
    private function statusOf(string $path, Principal $principal, array $body): int
    {
        $key = 'preview-' . md5($path . '|' . $principal->userId);

        return $this->fixture->call('POST', $path, $principal, $body, [], $key)->status;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(ApiResponse $response): array
    {
        self::assertIsArray($response->data);

        return $response->data;
    }

    private function gateFrom(int $lessonId, string $from): void
    {
        $response = $this->fixture->call(
            'PUT',
            '/manage/lessons/' . $lessonId . '/availability',
            $this->fixture->teacher(),
            [
                'tree' => [
                    'op' => 'and',
                    'c' => [[
                        'type' => DateCondition::KEY,
                        'payload' => StoredShape::wrap(['from' => $from, 'until' => null, 'tz' => 'UTC'], 1),
                    ]],
                ],
            ],
        );

        self::assertSame(200, $response->status, (string) json_encode($response->data));
    }
}
