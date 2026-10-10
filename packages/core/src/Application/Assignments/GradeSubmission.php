<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assignments;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assignments\AssignmentRepository;
use Shondalai\VidyaLMS\Core\Domain\Assignments\AssignmentRubric;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Submission;
use Shondalai\VidyaLMS\Core\Domain\Assignments\SubmissionRepository;
use Shondalai\VidyaLMS\Core\Domain\Events\SubmissionGraded;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

final class GradeSubmission
{
    public function __construct(
        private readonly SubmissionRepository $submissions,
        private readonly AssignmentRepository $assignments,
        private readonly CourseAccess $access,
        private readonly EventBus $events,
        private readonly DatabasePort $database,
        private readonly ClockPort $clock,
        /**
         * Optional so a harness that predates the registry still builds this
         * use case; absent, the raw points stand, which is what the free
         * "none" policy answers anyway.
         */
        private readonly ?ApplyLatePolicy $latePolicy = null,
    ) {
    }

    public function currentForGrader(Principal $principal, int $submissionId): Submission
    {
        $this->access->requireSignedIn($principal);

        $submission = $this->submissions->ofId($submissionId);

        if ($submission === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Submission', $submissionId);
        }

        $assignment = $this->assignments->ofId($submission->assignmentId);

        if ($assignment === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Assignment', $submission->assignmentId);
        }

        $lesson = $this->database->selectOne(
            Query::from('lessons')->select(['id', 'course_id'])->where('id', '=', $assignment->lessonId),
        );

        if ($lesson === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Lesson', $assignment->lessonId);
        }

        $this->access->require($principal, (int) $lesson['course_id'], Capability::GRADE);

        return $submission;
    }

    /** @param array<mixed> $criteria */
    public function execute(
        Principal $principal,
        int $submissionId,
        ?float $pointsAwarded,
        ?string $feedback,
        string $decision = 'auto',
        array $criteria = [],
    ): Submission {
        $submission = $this->currentForGrader($principal, $submissionId);
        $assignment = $this->assignments->ofId($submission->assignmentId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Assignment', $submission->assignmentId);
        $lesson = $this->database->selectOne(Query::from('lessons')->where('id', '=', $assignment->lessonId))
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Lesson', $assignment->lessonId);

        if (!$submission->isSubmitted()) {
            throw new ConflictException('Only a submitted submission can be graded.');
        }

        $isLatest = $submission->attemptNumber === $this->submissions->maxAttemptNumber($submission->assignmentId, $submission->userId);
        if (!in_array($decision, ['auto', 'approved', 'failed', 'resubmit'], true)) {
            throw ValidationException::forField('decision', 'Choose automatic, approved, failed or resubmit.');
        }
        $snapshot = $submission->assessment();
        $rubric = $snapshot['rubric'] ?? $assignment->rubric;
        $maxPoints = (float) ($snapshot['max_points'] ?? $assignment->maxPoints);
        $passPercentage = array_key_exists('pass_percentage', $snapshot) ? $snapshot['pass_percentage'] : $assignment->passPercentage;
        $result = AssignmentRubric::assess($rubric, $criteria);
        if ($rubric !== []) {
            $total = array_sum(array_column($result, 'points'));
            if ($pointsAwarded !== null && abs($total - $pointsAwarded) > 0.001) {
                throw ValidationException::forField('points_awarded', 'The score must equal the rubric total.');
            }
            $pointsAwarded = $total;
        }

        if ($pointsAwarded === null || !is_finite($pointsAwarded) || $pointsAwarded < 0 || $pointsAwarded > $maxPoints) {
            throw ValidationException::forField(
                'points_awarded',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('Points must be between 0 and %s.', rtrim(rtrim(number_format($maxPoints, 2, '.', ''), '0'), '.')),
            );
        }

        $now = $this->clock->now();

        // The late policy is asked here, which is the free code path the
        // registry needed: the key and its parameters are free columns on the
        // course, the free "none" provider marks late and changes nothing, and
        // a paid provider deducts without one line of its rule living in this
        // method. An unregistered key falls back to none and the outcome
        // records the substitution.
        $pointsAwarded = $this->afterLatePolicy(
            (int) $lesson['course_id'],
            $pointsAwarded,
            $maxPoints,
            $assignment->dueAt,
            $submission->submittedAt ?? $now,
        );

        $passed = $passPercentage === null || $pointsAwarded / $maxPoints * 100 >= $passPercentage;
        if ($decision === 'auto') {
            $decision = $passed ? 'approved' : 'failed';
        }
        if ($decision === 'approved' && !$passed) {
            throw ValidationException::forField('decision', 'The score is below the required pass percentage.');
        }
        if ($decision === 'resubmit' && ($feedback === null || trim($feedback) === '')) {
            throw ValidationException::forField('feedback', 'Explain what the learner needs to improve.');
        }
        $submission->grade($principal->userId, $pointsAwarded, $feedback, $now, [
            'decision' => $decision,
            'max_points' => $maxPoints,
            'pass_percentage' => $passPercentage,
            'rubric' => $rubric,
            'criteria' => $result,
        ]);
        $this->submissions->recordGrade($submission);

        $this->events->dispatch(new SubmissionGraded(
            $now,
            $submissionId,
            $submission->assignmentId,
            $assignment->lessonId,
            $submission->enrolmentId,
            $submission->userId,
            (int) $lesson['course_id'],
            $pointsAwarded,
            $maxPoints,
            $decision === 'approved' && $isLatest,
        ));

        return $submission;
    }

    /**
     * The awarded points after the course's late policy has had its say.
     *
     * The policy speaks in percentages, because that is what every late rule
     * in the field is expressed in and it is what makes an outcome replayable
     * across a change to the maximum. A maximum of zero has no percentage, so
     * the raw points stand.
     */
    private function afterLatePolicy(
        int $courseId,
        float $pointsAwarded,
        float $maxPoints,
        ?\DateTimeImmutable $dueAt,
        \DateTimeImmutable $submittedAt,
    ): float {
        if ($this->latePolicy === null || $maxPoints <= 0.0) {
            return $pointsAwarded;
        }

        $outcome = $this->latePolicy->forCourse(
            $courseId,
            round($pointsAwarded / $maxPoints * 100, 4),
            $dueAt,
            $submittedAt,
        );

        return round($outcome->percentage / 100 * $maxPoints, 2);
    }
}
