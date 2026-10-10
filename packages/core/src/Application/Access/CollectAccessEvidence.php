<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Access;

use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * What one learner actually did with one course, assembled for a chargeback.
 *
 * A site owner facing a dispute on a CMS-shop order needs this bundle whether
 * or not a paid package is installed, and nothing else in the product can
 * produce it: the shop knows the money moved and we know whether the content
 * was consumed, which is the half a shop cannot supply and the half that wins
 * a dispute.
 *
 * It is free and it is here in Wave A because the alternative is a site owner
 * discovering during a 120-day chargeback window that the evidence route ships
 * with the gateway package they did not buy.
 *
 * **It never throws inside a deadline.** The published direction for the
 * dispute-evidence seam is an empty bundle carrying a warning rather than an
 * exception, because an exception thrown inside a dispute deadline loses the
 * dispute. A learner with no entitlement and no activity is an answer, not an
 * error.
 *
 * The formatter for a particular gateway is a Wave B registration over this
 * same data. This use case is what it reads.
 */
final class CollectAccessEvidence
{
    /** How many timeline rows the bundle carries. Bounded: evidence, not an archive. */
    private const TIMELINE_LIMIT = 200;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly CourseAccess $access,
        private readonly ClockPort $clock,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(Principal $actor, int $userId, int $courseId): array
    {
        // Reports or teach on the course, exactly as every other per-course
        // read: the bundle names a learner, so it is a reports-scoped answer
        // and not an anonymous one.
        $this->access->require($actor, $courseId, Capability::VIEW_REPORTS, Capability::TEACH);

        $entitlements = $this->database->selectAll(
            Query::from('entitlements')
                ->select([
                    'id', 'source', 'source_ref', 'status', 'granted_by',
                    'starts_at', 'expires_at', 'revoked_reason', 'revoked_ref', 'created_at',
                ])
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $courseId)
                ->orderBy('id'),
        );

        $enrolment = $this->database->selectOne(
            Query::from('enrolments')
                ->select(['id', 'status', 'enrolled_at', 'completed_at', 'last_activity_at', 'progress_percent'])
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $courseId),
        );

        // Through the enrolment, because lesson_progress carries no course id:
        // counting on the user alone would report every lesson this person has
        // ever finished as evidence about one course.
        $lessons = $this->database->count(
            Query::from('lesson_progress', 'lp')
                ->join('enrolments', 'e', 'lp.enrolment_id', 'e.id')
                ->where('lp.user_id', '=', $userId)
                ->where('e.course_id', '=', $courseId)
                ->whereNotNull('lp.completed_at'),
        );

        $timeline = $this->database->selectAll(
            Query::from('activity_log')
                ->select(['action', 'lesson_id', 'occurred_at'])
                ->where('user_id', '=', $userId)
                ->where('course_id', '=', $courseId)
                // (occurred_at, id) is the stable, indexed, unique sort key,
                // and the pair rather than the instant alone because two
                // heartbeats inside one second are routine.
                ->orderBy('occurred_at', Query::SORT_DESC)
                ->orderBy('id', Query::SORT_DESC)
                ->limit(self::TIMELINE_LIMIT),
        );

        $warnings = [];

        if ($entitlements === []) {
            $warnings[] = 'no_entitlement_on_record';
        }

        if ($enrolment === null) {
            $warnings[] = 'no_enrolment_on_record';
        }

        return [
            'user_id' => $userId,
            'course_id' => $courseId,
            'collected_at' => $this->clock->now()->format(DATE_ATOM),
            'entitlements' => array_values($entitlements),
            'enrolment' => $enrolment,
            'lessons_completed' => $lessons,
            'attempts' => $this->attempts($userId, $courseId),
            'timeline' => array_values($timeline),
            'timeline_truncated' => \count($timeline) >= self::TIMELINE_LIMIT,
            'warnings' => $warnings,
        ];
    }

    private function attempts(int $userId, int $courseId): int
    {
        return $this->database->count(
            Query::from('attempts', 'a')
                ->join('quizzes', 'q', 'a.quiz_id', 'q.id')
                ->where('a.user_id', '=', $userId)
                ->where('q.course_id', '=', $courseId),
        );
    }
}
