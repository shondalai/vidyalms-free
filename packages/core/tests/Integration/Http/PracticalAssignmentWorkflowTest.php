<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\Report\TrainingEvidenceReport;
use Shondalai\VidyaLMS\Core\Registry\Context\ReportContext;
use Shondalai\VidyaLMS\Core\Registry\Context\VisibilityScope;

final class PracticalAssignmentWorkflowTest extends TestCase
{
    private ManagementFixture $site;
    private int $course;
    private int $assignment;
    private int $next;

    protected function setUp(): void
    {
        $this->site = ManagementFixture::create();
        $teacher = $this->site->teacher();
        $this->course = (int) $this->ok('POST', '/manage/courses', $teacher, ['title' => 'Practical baking', 'progression' => 'linear'])['id'];
        $this->ok('PATCH', "/manage/courses/{$this->course}", $teacher, ['progression' => 'linear']);
        $module = (int) $this->ok('POST', "/manage/courses/{$this->course}/modules", $teacher, ['title' => 'Preparation'])['id'];
        $this->assignment = (int) $this->ok('POST', "/manage/modules/{$module}/lessons", $teacher, ['title' => 'Photo assessment', 'type' => 'assignment'])['id'];
        $module2 = (int) $this->ok('POST', "/manage/courses/{$this->course}/modules", $teacher, ['title' => 'Finishing'])['id'];
        $this->next = (int) $this->ok('POST', "/manage/modules/{$module2}/lessons", $teacher, ['title' => 'Next step', 'type' => 'reading', 'content' => ['body' => '<p>Finish your cake.</p>']])['id'];
        $this->ok('PUT', "/manage/lessons/{$this->assignment}/assignment", $teacher, [
            'max_points' => 100, 'max_files' => 10, 'allowed_file_types' => ['jpg', 'png'], 'max_file_size_kb' => 8192,
            'pass_percentage' => 70, 'allow_resubmission' => false,
            'rubric' => [['id' => 'hygiene', 'label' => 'Hygiene', 'max_points' => 30], ['id' => 'technique', 'label' => 'Technique', 'max_points' => 70]],
        ]);
        $this->ok('POST', "/manage/courses/{$this->course}/publish", $teacher);
        $this->ok('POST', "/manage/courses/{$this->course}/learners", $teacher, ['user_id' => ManagementFixture::LEARNER_ID]);
        $stored = $this->site->env->database->selectOne(Query::from('courses')->where('id', '=', $this->course));
        self::assertNotNull($stored);
        self::assertSame('linear', $stored['progression']);
    }

    public function testPhotosResubmissionHistoryRubricAndCrossModuleApprovalGate(): void
    {
        $next = $this->ok('GET', "/lessons/{$this->next}", $this->site->learner());
        self::assertFalse($next['content_available']);
        self::assertSame('blocked', $next['access']['state']);
        $first = $this->submit(10);
        self::assertCount(10, $first['files']);
        $this->site->env->clock->advance('PT2H');
        $graded = $this->ok('POST', '/submissions/' . $first['id'] . '/grade', $this->site->teacher(), [
            'decision' => 'resubmit', 'feedback' => 'Improve hygiene and show the finished cake.',
            'criteria' => [['id' => 'hygiene', 'points' => 10, 'feedback' => 'Clean the workbench.'], ['id' => 'technique', 'points' => 30]],
        ]);
        self::assertEquals(40, $graded['points_awarded']);
        self::assertFalse($graded['outcome']['lesson_completed']);
        self::assertFalse($this->ok('GET', "/lessons/{$this->next}", $this->site->learner())['content_available']);
        $rules = $this->ok('GET', "/assignments/{$this->assignment}/submissions", $this->site->learner());
        self::assertTrue($rules['can_submit']);
        self::assertArrayNotHasKey('path', $rules['submissions'][0]['files'][0]);
        self::assertNotEmpty($rules['submissions'][0]['files'][0]['url']);
        $second = $this->submit(6);
        // Author edits cannot retrospectively change the rubric or pass mark of submitted work.
        $this->ok('PUT', "/manage/lessons/{$this->assignment}/assignment", $this->site->teacher(), ['max_points' => 10, 'pass_percentage' => 100]);
        $passed = $this->ok('POST', '/submissions/' . $second['id'] . '/grade', $this->site->teacher(), [
            'feedback' => 'Approved. Continue to finishing.',
            'criteria' => [['id' => 'hygiene', 'points' => 25], ['id' => 'technique', 'points' => 55]],
        ]);
        self::assertEquals(80, $passed['points_awarded']);
        self::assertTrue($passed['outcome']['lesson_completed']);
        self::assertTrue($this->ok('GET', "/lessons/{$this->next}", $this->site->learner())['content_available']);
        $history = $this->ok('GET', "/assignments/{$this->assignment}/submissions", $this->site->learner());
        self::assertFalse($history['can_submit']);
        self::assertCount(2, $history['submissions']);
        self::assertSame('resubmit', $history['submissions'][1]['assessment']['decision']);
        self::assertSame('Clean the workbench.', $history['submissions'][1]['assessment']['criteria'][0]['feedback']);
        self::assertCount(10, $history['submissions'][1]['files']);
        self::assertSame($first['submitted_at'], $history['submissions'][1]['submitted_at']);
        $report = new TrainingEvidenceReport('assignment-history', $this->site->env->database, $this->site->env->users, $this->site->env->translator);
        $rows = iterator_to_array($report->rows(ReportContext::create(['course_id' => $this->course], $this->site->teacher(), VisibilityScope::forUser(ManagementFixture::TEACHER_ID))));
        self::assertCount(2, $rows);
        self::assertStringContainsString('hygiene', $rows[0]['assessment']);
        self::assertStringContainsString('photo-10.png', $rows[0]['files']);
    }

    public function testAutomaticFailureCannotUnlockTheNextStep(): void
    {
        $first = $this->submit(6);
        $failed = $this->ok('POST', '/submissions/' . $first['id'] . '/grade', $this->site->teacher(), [
            'criteria' => [['id' => 'hygiene', 'points' => 15], ['id' => 'technique', 'points' => 20]],
        ]);
        self::assertSame('failed', $failed['assessment']['decision']);
        self::assertFalse($failed['outcome']['lesson_completed']);
        self::assertFalse($this->ok('GET', "/lessons/{$this->next}", $this->site->learner())['content_available']);
    }

    public function testGuestCannotReadAssignmentPhotosAndTooManyFilesAreRejected(): void
    {
        self::assertSame(401, $this->site->call('GET', "/assignments/{$this->assignment}/submissions", Principal::guest())->status);
        self::assertSame(422, $this->site->call('POST', "/assignments/{$this->assignment}/submissions", $this->site->learner(), ['files' => $this->photos(11)])->status);
        $files = $this->photos(1);
        $files[0]['filename'] = 'document.pdf';
        self::assertSame(422, $this->site->call('POST', "/assignments/{$this->assignment}/submissions", $this->site->learner(), ['files' => $files])->status);
    }

    public function testSuccessfulLoginsEnterTheTimeline(): void
    {
        $this->site->kernel->recordLogin->execute($this->site->learner());
        $row = $this->site->env->database->selectOne(Query::from('activity_log')->where('user_id', '=', ManagementFixture::LEARNER_ID)->where('action', '=', 'vidyalms.learner.signed_in'));
        self::assertNotNull($row);
    }

    public function testAuthorPreviewDoesNotOfferALearnerSubmission(): void
    {
        $preview = $this->ok('GET', "/assignments/{$this->assignment}/submissions", $this->site->teacher());
        self::assertFalse($preview['can_submit']);
    }

    /** @return array<string, mixed> */
    private function submit(int $photos): array
    {
        return $this->ok('POST', "/assignments/{$this->assignment}/submissions", $this->site->learner(), ['files' => $this->photos($photos)]);
    }

    /** @return list<array<string, mixed>> */
    private function photos(int $count): array
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aDQAAAABJRU5ErkJggg==', true);
        self::assertIsString($bytes);
        return array_map(function (int $i) use ($bytes): array {
            $path = 'submissions/50/photo-' . $i . '.png';
            $this->site->env->storage->putPrivate($path, $bytes);
            return ['path' => $path, 'filename' => 'photo-' . $i . '.png', 'size_bytes' => strlen($bytes), 'mime' => 'image/png'];
        }, range(1, $count));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function ok(string $method, string $path, Principal $principal, array $body = []): array
    {
        $response = $this->site->call($method, $path, $principal, $body);
        self::assertContains($response->status, [200, 201], $path . ': ' . json_encode($response));
        self::assertNotNull($response->data);
        return $response->data;
    }
}
