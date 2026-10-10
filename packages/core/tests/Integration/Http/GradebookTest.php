<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;

/**
 * The course gradebook: one queue interleaving assignment submissions and the
 * quiz answers a question type says a human must read, and the two grading
 * routes that drain it.
 */
final class GradebookTest extends TestCase
{
    private ManagementFixture $fixture;
    private int $assignmentId = 0;
    private int $attemptId = 0;

    protected function setUp(): void
    {
        $this->fixture = ManagementFixture::create();
        $this->seedGradingWork();
    }

    public function testTheQueueInterleavesBothSourcesOldestFirst(): void
    {
        $response = $this->gradebook();

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(
            ['items', 'total', 'page', 'per_page', 'pending_total', 'graded_total'],
            array_keys($response->data),
        );
        self::assertSame(2, $response->data['total']);
        self::assertSame(2, $response->data['pending_total']);
        self::assertSame(0, $response->data['graded_total']);

        $types = array_map(static fn (array $item): string => $item['type'], $response->data['items']);
        self::assertSame(['quiz_answer', 'submission'], $types, 'The attempt was submitted first.');
    }

    public function testEveryItemCarriesTheContractShape(): void
    {
        $items = $this->gradebook()->data['items'] ?? [];

        foreach ($items as $item) {
            self::assertSame(
                [
                    'key',
                    'type',
                    'status',
                    'learner',
                    'course_id',
                    'lesson_id',
                    'lesson_title',
                    'waiting_since',
                    'grade',
                    'submission',
                    'quiz_answer',
                ],
                array_keys($item),
            );
            self::assertSame(['id' => ManagementFixture::LEARNER_ID, 'name' => 'Lena Learner'], $item['learner']);
            self::assertNull($item['grade']);
        }
    }

    public function testAQuizAnswerCarriesThePromptAndTheLearnerAnswerButNeverCorrectness(): void
    {
        $items = $this->gradebook(['type' => 'quiz_answer'])->data['items'] ?? [];

        self::assertCount(1, $items);
        $answer = $items[0]['quiz_answer'];

        self::assertSame(
            ['attempt_id', 'quiz_id', 'quiz_title', 'attempt_number', 'question', 'answer', 'answered_at', 'rubric'],
            array_keys($answer),
        );

        // Null on a site with no rubric provider, which is what makes the
        // grading screen degrade to a points box rather than break. The
        // contract has declared the member since rubrics were designed, and
        // the shipped queue already posts the criteria shape back.
        self::assertNull($answer['rubric']);
        self::assertSame(['id', 'position', 'type', 'points', 'presentation'], array_keys($answer['question']));
        self::assertSame(['prompt' => 'Describe an oolong.'], $answer['question']['presentation']);
        self::assertArrayNotHasKey('accepted', $answer['question']['presentation']);
        self::assertSame('Partly oxidised.', $answer['answer']);
        self::assertSame('answer:' . $this->attemptId . ':' . $this->fixture->questionId, $items[0]['key']);
    }

    /**
     * The queue signs the question's own media and the learner's own files.
     *
     * It used to sign neither. The grader of a drawing saw a broken
     * background, the private storage path of every media-bearing item was
     * published as plain text, and no artifact type was markable at all,
     * because a grader asked to open a file has only a storage ref. All four
     * produced-response types default to external marking and are therefore
     * always in this queue, which makes it the one place the signing seam
     * matters most.
     */
    public function testAQuizAnswerCarriesSignedMediaAndSignedLearnerFiles(): void
    {
        $this->fixture->env->storage->putPrivate('attempts/' . ManagementFixture::LEARNER_ID . '/q1.png', 'a drawing');

        $answer = $this->fixture->env->database->update(
            'attempt_answers',
            ['answer' => json_encode(
                ['record' => [['name' => 'media', 'base' => ['file' => ['ref' => 'attempts/' . ManagementFixture::LEARNER_ID . '/q1.png']]]]],
                JSON_THROW_ON_ERROR,
            )],
            ['attempt_id' => $this->attemptId, 'question_id' => $this->fixture->questionId],
        );
        self::assertSame(1, $answer);

        $response = $this->gradebook(['type' => 'quiz_answer']);
        $items = $response->data['items'] ?? [];
        self::assertCount(1, $items, (string) json_encode($response->data));

        $file = $items[0]['quiz_answer']['answer']['record'][0]['base']['file'];

        // The stored ref stays, because a grade row and a regrade key on it,
        // and the signed, expiring URL sits beside it: a grader asked to mark
        // a file they cannot open cannot mark it.
        self::assertSame('attempts/' . ManagementFixture::LEARNER_ID . '/q1.png', $file['ref']);
        self::assertStringContainsString('signed', $file['url']);
        self::assertStringContainsString('expires=', $file['url']);
    }

    public function testASubmissionCarriesASignedUrlAndNeverItsStoragePath(): void
    {
        $items = $this->gradebook(['type' => 'submission'])->data['items'] ?? [];

        self::assertCount(1, $items);
        $submission = $items[0]['submission'];

        self::assertSame(
            [
                'id',
                'assignment_id',
                'enrolment_id',
                'attempt_number',
                'text_response',
                'submitted_at',
                'max_points',
                'allow_resubmission',
                'assessment',
                'files',
            ],
            array_keys($submission),
        );
        self::assertSame(['filename', 'size_bytes', 'mime', 'url'], array_keys($submission['files'][0]));
        self::assertStringContainsString('signed', $submission['files'][0]['url']);
        self::assertStringNotContainsString('submissions/50/notes.txt', json_encode($submission['files'][0], JSON_THROW_ON_ERROR));
        self::assertSame('submission:' . $submission['id'], $items[0]['key']);
    }

    public function testGradingAnAnswerReportsWhatItDid(): void
    {
        $response = $this->fixture->call(
            'POST',
            '/attempts/' . $this->attemptId . '/answers/' . $this->fixture->questionId . '/grade',
            $this->fixture->teacher(),
            ['points_awarded' => 5, 'feedback' => 'Good enough.'],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertSame(['attempt', 'outcome'], array_keys($response->data));
        self::assertSame(
            [
                'id',
                'quiz_id',
                'attempt_number',
                'status',
                'started_at',
                'submitted_at',
                'expires_at',
                // The frozen policy the runner obeys, read from the attempt
                // and never from the quiz.
                'runtime',
                'score',
                'max_score',
                'percentage',
                'passed',
            ],
            array_keys($response->data['attempt']),
        );
        self::assertSame('graded', $response->data['attempt']['status']);
        self::assertSame(5.0, $response->data['attempt']['score']);
        self::assertSame(
            ['lesson_completed', 'enrolment_completed', 'attempt_finalised', 'pending_manual_count'],
            array_keys($response->data['outcome']),
        );
        self::assertTrue($response->data['outcome']['attempt_finalised']);
        self::assertSame(0, $response->data['outcome']['pending_manual_count']);
        self::assertTrue($response->data['outcome']['lesson_completed']);
    }

    public function testAGradedAnswerMovesToTheHistoryWithItsGrade(): void
    {
        $this->fixture->call(
            'POST',
            '/attempts/' . $this->attemptId . '/answers/' . $this->fixture->questionId . '/grade',
            $this->fixture->teacher(),
            ['points_awarded' => 4],
        );

        $pending = $this->gradebook();
        self::assertNotNull($pending->data);
        self::assertSame(1, $pending->data['total']);
        self::assertSame(1, $pending->data['pending_total']);
        self::assertSame(1, $pending->data['graded_total']);

        $graded = $this->gradebook(['status' => 'graded']);
        self::assertNotNull($graded->data);
        self::assertCount(1, $graded->data['items']);
        self::assertSame(
            ['points_awarded', 'max_points', 'feedback', 'graded_at', 'grader_id'],
            array_keys($graded->data['items'][0]['grade']),
        );
        self::assertSame(4.0, $graded->data['items'][0]['grade']['points_awarded']);
        self::assertSame(ManagementFixture::TEACHER_ID, $graded->data['items'][0]['grade']['grader_id']);
    }

    public function testGradingASubmissionReportsWhatItDid(): void
    {
        $queue = $this->gradebook(['type' => 'submission']);
        self::assertNotNull($queue->data);
        $submission = $queue->data['items'][0]['submission'];

        $response = $this->fixture->call(
            'POST',
            '/submissions/' . $submission['id'] . '/grade',
            $this->fixture->teacher(),
            ['points_awarded' => 80, 'feedback' => 'Nicely observed.'],
        );

        self::assertSame(200, $response->status);
        self::assertNotNull($response->data);
        self::assertArrayHasKey('outcome', $response->data);
        self::assertSame(['lesson_completed', 'enrolment_completed'], array_keys($response->data['outcome']));
        self::assertTrue($response->data['outcome']['lesson_completed']);
        self::assertSame('graded', $response->data['status']);
    }

    public function testTheQueuePages(): void
    {
        $first = $this->gradebook(['per_page' => '1']);
        self::assertNotNull($first->data);
        self::assertCount(1, $first->data['items']);
        self::assertSame(2, $first->data['total'], 'The total counts the whole queue, not the page.');

        $second = $this->gradebook(['per_page' => '1', 'page' => '2']);
        self::assertNotNull($second->data);
        self::assertCount(1, $second->data['items']);
        self::assertNotSame($first->data['items'][0]['key'], $second->data['items'][0]['key']);
    }

    public function testTheWorkbenchBadgeCountsTheSameQueue(): void
    {
        $course = $this->fixture->call('GET', $this->fixture->path('/manage/courses/{course}'), $this->fixture->teacher());

        self::assertNotNull($course->data);
        self::assertSame(2, $course->data['pending_grading_count']);
    }

    public function testAnAnswerOfAQuizWithNoLessonCarriesNoLessonId(): void
    {
        $teacher = $this->fixture->teacher();
        $learner = $this->fixture->learner();

        $quiz = $this->fixture->call('POST', $this->fixture->path('/manage/courses/{course}/quizzes'), $teacher, [
            'title' => 'Standalone check',
            'policy' => ['opens_at' => '2020-01-01T00:00:00Z'],
        ]);
        self::assertSame(201, $quiz->status);
        self::assertNotNull($quiz->data);
        self::assertNull($quiz->data['lesson_id'], 'a quiz need not hang off a lesson');
        $quizId = (int) $quiz->data['id'];

        $question = $this->fixture->call('POST', '/manage/quizzes/' . $quizId . '/questions', $teacher, [
            'type' => 'short_answer',
            'definition' => ['prompt' => 'Why oolong?', 'accepted' => []],
            'points' => 5,
        ]);
        self::assertSame(201, $question->status);
        self::assertNotNull($question->data);

        $attempt = $this->fixture->call('POST', '/quizzes/' . $quizId . '/attempts', $learner);
        self::assertSame(201, $attempt->status);
        self::assertNotNull($attempt->data);
        $attemptId = (int) $attempt->data['id'];

        $this->fixture->call('PUT', '/attempts/' . $attemptId . '/answers', $learner, [
            'answers' => [(string) $question->data['id'] => 'Because it is rounded.'],
        ]);
        $this->fixture->env->clock->advance('PT1M');
        $this->fixture->call('POST', '/attempts/' . $attemptId . '/submit', $learner);

        $items = $this->gradebook(['type' => 'quiz_answer'])->data['items'] ?? [];
        $standalone = null;

        foreach ($items as $item) {
            if ($item['quiz_answer']['quiz_id'] === $quizId) {
                $standalone = $item;
            }
        }

        self::assertNotNull($standalone, 'the unlinked quiz answer is in the queue');
        self::assertNull($standalone['lesson_id'], 'no lesson, so no lesson id: never zero');
        self::assertSame('Standalone check', $standalone['lesson_title']);
    }

    public function testTheDashboardCountsGradingWorkOlderThanTheWindow(): void
    {
        $this->fixture->env->clock->advance('P5D');

        $response = $this->fixture->call('GET', '/admin/dashboard', $this->fixture->manager());

        self::assertNotNull($response->data);
        self::assertSame(1, $response->data['attention']['grading_waiting']['submissions']);
        self::assertSame(1, $response->data['attention']['grading_waiting']['attempts']);
        self::assertSame(1, $response->data['attention']['grading_waiting']['courses']);
        self::assertSame(3, $response->data['attention']['grading_waiting']['older_than_days']);
    }

    /**
     * @param array<string, string> $query
     */
    private function gradebook(array $query = []): ApiResponse
    {
        return $this->fixture->call(
            'GET',
            $this->fixture->path('/manage/courses/{course}/gradebook'),
            $this->fixture->teacher(),
            [],
            $query,
        );
    }

    /**
     * The queue decides pending-human from the frozen definition, so an
     * authoring edit after submission cannot silently empty it.
     *
     * Adding one accepted spelling to a submitted short answer used to take
     * the item out of the queue entirely: the attempt stayed at submitted with
     * a null pass flag, its grade row stayed at pending_external_scoring, and
     * there was no longer a queue row a grader could close it from. The same
     * edit also made this route and the pending count on the attempt disagree
     * about the same attempt, because that one already read the frozen
     * definition.
     */
    public function testAnAuthoringEditAfterSubmissionDoesNotEmptyTheQueue(): void
    {
        self::assertSame(1, $this->gradebook(['type' => 'quiz_answer'])->data['total'] ?? 0);

        $edited = $this->fixture->call(
            'PUT',
            '/manage/questions/' . $this->fixture->questionId,
            $this->fixture->teacher(),
            [
                'definition' => ['prompt' => 'Describe an oolong.', 'accepted' => ['partly oxidised']],
                'points' => 1,
            ],
        );
        self::assertSame(200, $edited->status, json_encode($edited->data, JSON_THROW_ON_ERROR));

        $after = $this->gradebook(['type' => 'quiz_answer']);

        self::assertSame(1, $after->data['total'] ?? 0, 'the pending essay is still in the instructor queue');
        // pending_total counts the whole course queue rather than the filtered
        // page, so it is the essay plus the seeded assignment submission.
        self::assertSame(2, $after->data['pending_total'] ?? 0);
        self::assertSame(
            'answer:' . $this->attemptId . ':' . $this->fixture->questionId,
            $after->data['items'][0]['key'] ?? null,
        );
    }

    private function seedGradingWork(): void
    {
        $learner = $this->fixture->learner();

        $attempt = $this->fixture->call('POST', '/quizzes/' . $this->fixture->quizId . '/attempts', $learner);
        self::assertSame(201, $attempt->status);
        self::assertNotNull($attempt->data);
        $this->attemptId = $attempt->data['id'];

        $this->fixture->call('PUT', '/attempts/' . $this->attemptId . '/answers', $learner, [
            'answers' => [(string) $this->fixture->questionId => 'Partly oxidised.'],
        ]);

        $this->fixture->env->clock->advance('PT1M');
        self::assertSame(200, $this->fixture->call('POST', '/attempts/' . $this->attemptId . '/submit', $learner)->status);

        // The submission route addresses the assignment by its lesson, which
        // is what a learner has a link to.
        $this->assignmentId = $this->fixture->assignmentLessonId;

        $this->fixture->env->storage->putPrivate('submissions/50/notes.txt', 'Tasting notes.');
        $this->fixture->env->clock->advance('PT1M');

        $submitted = $this->fixture->call('POST', '/assignments/' . $this->assignmentId . '/submissions', $learner, [
            'text_response' => 'A rounded, roasted oolong.',
            'files' => [[
                'path' => 'submissions/50/notes.txt',
                'filename' => 'notes.txt',
                'size_bytes' => 14,
                'mime' => 'text/plain',
            ]],
        ]);
        self::assertSame(201, $submitted->status, json_encode($submitted->data, JSON_THROW_ON_ERROR));
    }
}
