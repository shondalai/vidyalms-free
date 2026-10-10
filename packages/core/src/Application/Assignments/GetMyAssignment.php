<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assignments;

use Shondalai\VidyaLMS\Core\Application\Curriculum\GetLessonForLearner;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assignments\AssignmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Assignments\SubmissionRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Http\Handler\Presenter;
use Shondalai\VidyaLMS\Core\Http\Presenter\AssessmentPresenter;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;

final class GetMyAssignment
{
    public function __construct(
        private readonly GetLessonForLearner $lessons,
        private readonly AssignmentRepository $assignments,
        private readonly SubmissionRepository $submissions,
        private readonly AssessmentPresenter $presenter,
        private readonly StoragePort $storage,
        private readonly ClockPort $clock,
        private readonly DatabasePort $database,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(Principal $principal, int $lessonId): array
    {
        if ($principal->isGuest()) {
            throw new NotAuthorizedException('Sign in to view your assignment.');
        }
        $lesson = $this->lessons->execute($principal, $lessonId);
        if (!$lesson['content_available'] || ($lesson['access']['state'] ?? '') !== 'available') {
            throw new NotAuthorizedException('This assignment is not yet available.');
        }
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $assignment = $this->assignments->ofLesson($lessonId) ?? throw EntityNotFoundException::for('Assignment', $lessonId);
        $attempts = $this->submissions->listForUser((int) $assignment->id, $principal->userId);
        $rows = [];
        foreach ($attempts as $attempt) {
            $row = Presenter::submission($attempt);
            $row['files'] = array_map(fn ($file): array => [
                'filename' => $file->filename, 'size_bytes' => $file->sizeBytes, 'mime' => $file->mime,
                'url' => $this->storage->signedUrl($file->path, 3600),
            ], $attempt->files);
            $rows[] = $row;
        }
        $latest = $attempts[0] ?? null;
        $overdue = $assignment->isOverdueAt($this->clock->now());
        $decision = $latest?->assessment()['decision'] ?? null;
        $enrolment = $this->database->selectOne(Query::from('enrolments')
            ->where('course_id', '=', $lesson['course_id'])->where('user_id', '=', $principal->userId));
        return [
            'assignment' => $this->presenter->assignment($assignment),
            'submissions' => $rows,
            'can_submit' => ($enrolment['status'] ?? '') === 'active'
                && $decision !== 'approved' && (!$overdue || $decision === 'resubmit')
                && ($latest === null || $assignment->allowResubmission || $decision === 'resubmit'),
            'overdue' => $overdue,
        ];
    }
}
