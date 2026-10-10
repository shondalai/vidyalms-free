<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class PrivateReplayAuthorizationTest extends TestCase
{
    public function testRevokedEnrolmentCannotSaveSubmitOrReplayAnAttempt(): void
    {
        $f = ManagementFixture::create();
        $startPath = '/quizzes/' . $f->quizId . '/attempts';
        $started = $f->call('POST', $startPath, $f->learner(), idempotencyKey: 'start');
        self::assertSame(201, $started->status);
        self::assertNotNull($started->data);
        self::assertArrayHasKey('id', $started->data);
        $id = (int) $started->data['id'];
        $body = ['answers' => [$f->questionId => 'Private draft']];
        self::assertSame(200, $f->call('PUT', '/attempts/' . $id . '/answers', $f->learner(), $body, idempotencyKey: 'save')->status);
        $f->env->database->update('enrolments', ['status' => 'cancelled'], ['user_id' => 50, 'course_id' => $f->courseId]);
        self::assertSame(403, $f->call('PUT', '/attempts/' . $id . '/answers', $f->learner(), $body, idempotencyKey: 'save')->status);
        self::assertSame(403, $f->call('PUT', '/attempts/' . $id . '/answers', $f->learner(), $body)->status);
        self::assertSame(403, $f->call('POST', '/attempts/' . $id . '/submit', $f->learner())->status);
        self::assertSame(403, $f->call('POST', $startPath, $f->learner(), idempotencyKey: 'start')->status);
    }

    public function testAssignmentReplayCannotReadAfterAccessIsRevoked(): void
    {
        $f = ManagementFixture::create();
        $path = '/assignments/' . $f->assignmentLessonId . '/submissions';
        $body = ['text_response' => 'Private essay', 'files' => []];
        $first = $f->call('POST', $path, $f->learner(), $body, idempotencyKey: 'essay');
        self::assertSame(201, $first->status, json_encode($first->data, JSON_THROW_ON_ERROR));
        self::assertEquals($first->data, $f->call('POST', $path, $f->learner(), $body, idempotencyKey: 'essay')->data);
        $f->env->database->update('enrolments', ['status' => 'cancelled'], ['user_id' => 50, 'course_id' => $f->courseId]);
        $replay = $f->call('POST', $path, $f->learner(), $body, idempotencyKey: 'essay');
        self::assertSame(403, $replay->status);
        self::assertStringNotContainsString('Private essay', json_encode($replay->data, JSON_THROW_ON_ERROR));
        self::assertSame(1, $f->env->database->count(Query::from('submissions')));
    }

    public function testGradingReplayRechecksInstructorship(): void
    {
        $f = ManagementFixture::create();
        $submitted = $f->call('POST', '/assignments/' . $f->assignmentLessonId . '/submissions', $f->learner(), ['text_response' => 'Private essay']);
        self::assertSame(201, $submitted->status);
        self::assertNotNull($submitted->data);
        self::assertArrayHasKey('id', $submitted->data);
        $path = '/submissions/' . $submitted->data['id'] . '/grade';
        $body = ['points_awarded' => 1, 'feedback' => 'Confidential feedback'];
        $first = $f->call('POST', $path, $f->teacher(), $body, idempotencyKey: 'grade');
        self::assertSame(200, $first->status, json_encode($first->data, JSON_THROW_ON_ERROR));
        self::assertEquals($first->data, $f->call('POST', $path, $f->teacher(), $body, idempotencyKey: 'grade')->data);
        $f->env->database->delete('course_instructors', ['course_id' => $f->courseId, 'user_id' => ManagementFixture::TEACHER_ID]);
        $replay = $f->call('POST', $path, $f->teacher(), $body, idempotencyKey: 'grade');
        self::assertSame(403, $replay->status);
        self::assertStringNotContainsString('Confidential feedback', json_encode($replay->data, JSON_THROW_ON_ERROR));
    }

    public function testQuizSubmissionReplayUsesCurrentDisclosureAndAccess(): void
    {
        $f = ManagementFixture::create();
        $started = $f->call('POST', '/quizzes/' . $f->quizId . '/attempts', $f->learner());
        self::assertSame(201, $started->status);
        self::assertNotNull($started->data);
        self::assertArrayHasKey('id', $started->data);
        $attemptId = $started->data['id'];
        $f->call('PUT', '/attempts/' . $attemptId . '/answers', $f->learner(), ['answers' => [$f->questionId => 'My answer']]);
        $path = '/attempts/' . $attemptId . '/submit';
        self::assertSame(200, $f->call('POST', $path, $f->learner(), idempotencyKey: 'submit')->status);
        $f->env->database->update('quizzes', ['show_results' => 'never'], ['id' => $f->quizId]);
        $replay = $f->call('POST', $path, $f->learner(), idempotencyKey: 'submit');
        $current = $f->call('GET', '/attempts/' . $attemptId, $f->learner());
        self::assertSame(200, $replay->status);
        self::assertSame($current->data, $replay->data);
        self::assertSame('true', $replay->headers['Idempotent-Replayed']);
        $f->env->database->update('enrolments', ['status' => 'cancelled'], ['user_id' => 50, 'course_id' => $f->courseId]);
        self::assertSame(403, $f->call('POST', $path, $f->learner(), idempotencyKey: 'submit')->status);
    }
}
