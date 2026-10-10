<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Progress;

use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityEngine;
use Shondalai\VidyaLMS\Core\Application\Access\Availability\AvailabilityRefusal;
use Shondalai\VidyaLMS\Core\Application\Curriculum\LessonAvailability;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Application\Organisation\RequireCurrentOrganisationAccess;
use Shondalai\VidyaLMS\Core\Domain\Access\Availability\GateSubject;
use Shondalai\VidyaLMS\Core\Domain\Events\LessonCompleted;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgressRepository;
use Shondalai\VidyaLMS\Core\Domain\Progress\VideoPosition;
use Shondalai\VidyaLMS\Core\Domain\Progress\VideoPositionRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;

/**
 * The 15-second heartbeat from the player. Replay-safe by construction: an
 * idempotent upsert keyed on enrolment and lesson, monotonic transitions,
 * and a per-heartbeat cap on time spent so a replayed request cannot inflate
 * counters. Only lesson types that complete on view may complete here; pass
 * and submission types complete exclusively through the server-side
 * listeners, so the client's completed flag is ignored for them.
 *
 * Active and completed enrolments may record: a learner reviewing a course
 * they finished keeps accumulating seconds_spent, position_seconds and
 * last_heartbeat_at. Completion state stays terminal throughout, because the
 * lesson transition guard never re-completes a completed lesson and the
 * recompute's guarded update only moves an active enrolment, so re-watching
 * can never re-dispatch LessonCompleted or CourseCompleted. A suspended
 * enrolment is refused with a conflict.
 */
final class RecordLessonProgress
{
    private const MAX_SECONDS_PER_HEARTBEAT = 120;
    private const ENROLMENT_STATUS_ACTIVE = 'active';
    private const ENROLMENT_STATUS_COMPLETED = 'completed';
    private const ENROLMENT_STATUS_SUSPENDED = 'suspended';

    public function __construct(
        private readonly LessonProgressRepository $lessonProgress,
        private readonly VideoPositionRepository $videoPositions,
        private readonly RecomputeCourseProgress $recomputeCourseProgress,
        private readonly LessonTypeRegistry $lessonTypes,
        private readonly AccessGuard $guard,
        private readonly EventBus $events,
        private readonly ClockPort $clock,
        private readonly DatabasePort $database,
        private readonly LessonAvailability $availability,
        /**
         * Optional so a harness that predates the restriction engine still
         * builds this use case; absent, the structural answer is the only
         * answer, which is exactly the hole a drip-locked lesson walked
         * through, and is why the kernel always supplies one.
         */
        private readonly ?AvailabilityEngine $engine = null,
    ) {
    }

    public function execute(
        Principal $principal,
        int $enrolmentId,
        int $lessonId,
        ?float $progressPercent = null,
        ?int $secondsDelta = null,
        ?int $positionSeconds = null,
        bool $completed = false,
    ): void {
        $this->database->transactional(function () use (
            $principal,
            $enrolmentId,
            $lessonId,
            $progressPercent,
            $secondsDelta,
            $positionSeconds,
            $completed,
        ): void {
            $this->record($principal, $enrolmentId, $lessonId, $progressPercent, $secondsDelta, $positionSeconds, $completed);
        });
    }

    /**
     * Replay imported history without publishing the course. Only the site
     * manager running the import can enter here; the public heartbeat has
     * no option to enable this path. Progress and events belong to the
     * enrolment's learner, never to the operator.
     */
    public function importCompletion(Principal $operator, int $enrolmentId, int $lessonId): void
    {
        $this->guard->require($operator, Capability::MANAGE);
        $this->database->transactional(function () use ($operator, $enrolmentId, $lessonId): void {
            $this->record($operator, $enrolmentId, $lessonId, null, null, null, true, importing: true);
        });
    }

    private function record(
        Principal $principal,
        int $enrolmentId,
        int $lessonId,
        ?float $progressPercent,
        ?int $secondsDelta,
        ?int $positionSeconds,
        bool $completed,
        bool $importing = false,
    ): void {
        $this->guard->requireAuthenticated($principal);
        $this->validate($progressPercent, $secondsDelta, $positionSeconds);

        $enrolment = $this->database->selectOne(
            Query::from('enrolments')->where('id', '=', $enrolmentId),
        );

        if ($enrolment === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Enrolment', $enrolmentId);
        }

        if ($importing) {
            $principal = Principal::user((int) $enrolment['user_id']);
        }

        if ((int) $enrolment['user_id'] !== $principal->userId) {
            throw new NotAuthorizedException('The enrolment does not belong to the current user.');
        }

        // Serialize heartbeats for this enrolment, including the first insert.
        // A per-request cap alone still credits simultaneous/replayed requests.
        $this->database->update('enrolments', ['id' => $enrolmentId], ['id' => $enrolmentId]);
        $enrolment = $this->database->selectOne(Query::from('enrolments')->where('id', '=', $enrolmentId))
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Enrolment', $enrolmentId);

        $status = (string) $enrolment['status'];

        if ($status !== self::ENROLMENT_STATUS_ACTIVE && $status !== self::ENROLMENT_STATUS_COMPLETED) {
            throw new ConflictException($status === self::ENROLMENT_STATUS_SUSPENDED
                ? 'The enrolment is suspended; progress cannot be recorded.'
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                : sprintf('The enrolment is %s; progress can only be recorded on an active or completed enrolment.', $status));
        }

        $courseId = (int) $enrolment['course_id'];
        RequireCurrentOrganisationAccess::check($this->database, $this->clock, $principal->userId, $courseId);
        $scope = $this->engine?->scopeFor($principal, null, $enrolment);

        $lesson = $importing
            ? $this->availability->forCompletionImport($lessonId, $courseId)
            : $this->availability->forLearner($lessonId, $courseId);

        if ($lesson === null) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw EntityNotFoundException::for('Lesson', $lessonId);
        }

        // Availability enforced only in the player is not enforced. Without
        // this the outline correctly reported a drip-locked lesson blocked
        // with the instant it opens, and the same learner completed it by
        // posting the raw heartbeat: the item stayed in the denominator, the
        // numerator counted it too, the course completed early and a
        // certificate was issued for work nobody could have done.
        if ($scope !== null) {
            $refusal = AvailabilityRefusal::for(
                $scope->entryDecision(GateSubject::TYPE_LESSON, $lessonId),
                'Lesson',
                $lessonId,
            );

            if ($refusal !== null) {
                throw $refusal;
            }
        }

        $now = $this->clock->now();

        $progress = $this->lessonProgress->findByEnrolmentAndLesson($enrolmentId, $lessonId)
            ?? LessonProgress::start($enrolmentId, $lessonId, $principal->userId, $now);

        $previousHeartbeat = $progress->lastHeartbeatAt();
        $elapsed = $previousHeartbeat === null ? 0 : max(0, $now->getTimestamp() - $previousHeartbeat->getTimestamp());
        // The enrolment is locked above and recomputation advances this shared
        // timestamp. Parallel lesson tabs cannot each claim the same interval.
        $lastActivity = $enrolment['last_activity_at'] ?? null;
        if (\is_string($lastActivity) && $lastActivity !== '') {
            $lastActivityAt = new \DateTimeImmutable($lastActivity, new \DateTimeZone('UTC'));
            $elapsed = min($elapsed, max(0, $now->getTimestamp() - $lastActivityAt->getTimestamp()));
        }
        if ($secondsDelta !== null) {
            $progress->addSecondsSpent(min($secondsDelta, $elapsed, self::MAX_SECONDS_PER_HEARTBEAT));
        }
        $progress->recordHeartbeat($now);

        if (!$importing && (string) $lesson['type'] === 'video') {
            $duration = (int) ($lesson['duration_seconds'] ?? 0);
            if ($duration < 1 && ($completed || ($progressPercent ?? 0) >= 90.0)) {
                throw new ConflictException('This video needs a duration before completion can be recorded. '
                    . 'Ask the course instructor to set its duration.');
            }
            $progressPercent = $duration > 0 ? min(100.0, 100.0 * $progress->secondsSpent() / $duration) : 0.0;
            $completed = $duration > 0 && $progressPercent >= 90.0;
        }

        if ($progressPercent !== null) {
            $progress->raiseProgressPercent($progressPercent);
        }

        if ($positionSeconds !== null) {
            $progress->moveTo($positionSeconds);
        }

        $transitioned = false;

        if ($this->completesOnView((string) $lesson['type']) && ($completed || $progress->progressPercent() >= 100.0)) {
            $transitioned = $progress->complete($now);
        }

        $this->lessonProgress->save($progress);

        if ($positionSeconds !== null) {
            $this->videoPositions->save(new VideoPosition(
                $principal->userId,
                $lessonId,
                max(0, $positionSeconds),
                $lesson['duration_seconds'] === null ? null : (int) $lesson['duration_seconds'],
            ));
        }

        if ($transitioned) {
            $this->events->dispatch(new LessonCompleted($now, $enrolmentId, $lessonId, $principal->userId, $courseId));
        }

        $this->recomputeCourseProgress->execute($principal, $enrolmentId);
    }

    private function validate(?float $progressPercent, ?int $secondsDelta, ?int $positionSeconds): void
    {
        // Error keys carry the wire field names of the heartbeat request, not
        // the PHP parameter names, so clients can map them onto their form.
        if ($progressPercent !== null && (!is_finite($progressPercent) || $progressPercent < 0.0 || $progressPercent > 100.0)) {
            throw ValidationException::forField('progress_percent', 'Must be between 0 and 100.');
        }

        if ($secondsDelta !== null && $secondsDelta < 0) {
            throw ValidationException::forField('seconds_delta', 'Must be zero or positive.');
        }

        if ($positionSeconds !== null && $positionSeconds < 0) {
            throw ValidationException::forField('position_seconds', 'Must be zero or positive.');
        }
    }

    /**
     * Unknown lesson types never complete on view: a type whose provider is
     * not installed can still record time, but completion waits for the
     * package that understands it.
     */
    private function completesOnView(string $lessonType): bool
    {
        if (!$this->lessonTypes->has($lessonType)) {
            return false;
        }

        return $this->lessonTypes->get($lessonType)->completionMode() === LessonTypeInterface::COMPLETION_ON_VIEW;
    }
}
