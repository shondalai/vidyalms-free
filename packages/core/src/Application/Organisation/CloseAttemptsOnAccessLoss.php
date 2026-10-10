<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Application\Assessment\AttemptClosureContext;
use Shondalai\VidyaLMS\Core\Application\Assessment\SubmitAttempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;

/**
 * The attempt half of the revocation ceremony.
 *
 * An organisation administrator reclaiming a seat at minute forty of a sixty
 * minute attempt used to leave the learner unable to save and unable to
 * submit: the guard on the assessment path threw a conflict, the answers
 * stayed in attempt_answer_events, and no grade was ever written. That guard
 * is still there and is still right, as the post-hoc check for a client that
 * has not noticed, but it was standing in for a ceremony that did not exist.
 *
 * This is that ceremony. Every revoking path closes the holder's open
 * attempts on the course inside the transaction that revokes their access,
 * under a disposition the caller chose and defaulting to submit_and_grade,
 * and stamps the ledger row that caused it onto each attempt so the learner
 * and an auditor can both see why it ended.
 *
 * **The attempt query lives here rather than in a policy**, which is the
 * design's own rule: a policy object that could see an open attempt would be
 * a second place that knows how attempts work.
 */
final class CloseAttemptsOnAccessLoss
{
    /**
     * How many attempts one revocation closes synchronously.
     *
     * A learner has at most a handful of attempts open on one course, so this
     * is a guard against a pathological row set rather than a paging window;
     * the number is the same order as the termination drain's own chunk.
     */
    public const MAX_ATTEMPTS = 50;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly SubmitAttempt $submit,
        private readonly OrganisationLearningEvents $events,
    ) {
    }

    /**
     * Closes every attempt the holder still has open on the course, and
     * answers which ones it closed.
     *
     * **It runs before the entitlement is revoked, and the order is
     * load-bearing.** RevokeEntitlement's last-access branch abandons every
     * resumable attempt on the enrolment outright: answers preserved, no
     * grade, which is the same stranding under a different name. Grading
     * first leaves that branch nothing to abandon, and the learner keeps the
     * work they did.
     *
     * The events the graded submissions produce are staged rather than
     * dispatched, because this runs inside the revoking transaction: a rolled
     * back revocation must not have emitted a submission, and the certificate
     * listener must run after the commit so it sees the revoked entitlement
     * and declines to issue against it.
     *
     * @return list<int>
     */
    public function close(int $userId, int $courseId, string $disposition, string $reasonKey): array
    {
        $open = $this->database->selectAll(Query::from('attempts', 'a')->select(['a.id'])
            ->join('quizzes', 'q', 'a.quiz_id', 'q.id')
            ->where('a.user_id', '=', $userId)
            ->where('q.course_id', '=', $courseId)
            ->whereIn('a.status', [Attempt::STATUS_IN_PROGRESS, Attempt::STATUS_OVERDUE])
            ->orderBy('a.id')
            ->limit(self::MAX_ATTEMPTS));

        $closed = [];

        foreach ($open as $row) {
            $attemptId = (int) $row['id'];
            $this->events->stage($this->submit->executeForAccessClosure(
                AttemptClosureContext::forAccessTermination($attemptId, $disposition, $reasonKey)
            ));
            $closed[] = $attemptId;
        }

        return $closed;
    }

    /**
     * Records why each attempt ended, and which ledger row caused it.
     *
     * Separate from {@see close()} because the seat event that justifies the
     * closure is written after the revocation it belongs to, and the row's id
     * does not exist until then. A caller with no ledger row behind it passes
     * null, which is a real case: an organisation grant that spends no seat
     * writes no seat event, and inventing one would put a number in the
     * ledger that no balance depends on.
     *
     * It is written unconditionally, including for a voided attempt that
     * published no event, because an abandoned attempt with nothing on it
     * saying who abandoned it is exactly what the learner cannot explain.
     *
     * @param list<int> $attemptIds
     */
    public function record(array $attemptIds, string $reasonKey, ?int $seatEventId): void
    {
        foreach ($attemptIds as $attemptId) {
            $this->database->update(
                'attempts',
                ['closure_reason' => $reasonKey, 'closed_by_seat_event_id' => $seatEventId],
                ['id' => $attemptId]
            );
        }
    }
}
