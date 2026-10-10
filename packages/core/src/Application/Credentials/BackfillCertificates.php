<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Credentials;

use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Port\Database\Predicate;

/**
 * The reconciliation sweep for issuance: completed enrolments with no live
 * certificate, re-driven.
 *
 * It exists because completion is terminal and CourseCompleted cannot
 * re-fire. The guarded progress update only moves an active enrolment, so
 * re-watching a lesson can never re-dispatch it. If an issuance ever fails
 * (two learners completing in the same second and racing on the status list
 * counter, one losing the unique index, a template lock deadlock), the
 * enrolment stays complete for ever, the learner never gets a certificate,
 * and without this nothing in the product would ever look for the gap.
 *
 * Two defects found in this wave widened that gap: a certificate carrying an
 * issuer-supplied earner id could not be reissued at all, and a lapsed
 * certificate held the exactly-once key for ever. Both are fixed, and both
 * are the shape of failure this sweep is the safety net for.
 *
 * Binned like every other sweep, because enumerating every enrolment on a
 * large site in one run is the failure this project already fixed once.
 */
final class BackfillCertificates
{
    public const LAST_RUN_SETTING = 'certificate_backfill.last_run_at';

    /** How many enrolments one bin of one run re-drives. */
    public const BATCH_SIZE = 50;

    public function __construct(
        private readonly DatabasePort $database,
        private readonly IssueCertificate $issue,
        private readonly ClockPort $clock,
        private readonly ?Settings $settings = null,
        private readonly ?\Shondalai\VidyaLMS\Core\Domain\Access\EntitlementRepository $entitlements = null,
    ) {
    }

    /**
     * @return int how many certificates this run issued
     */
    public function execute(int $binIndex = 0, int $binCount = 1): int
    {
        $now = $this->clock->now();
        $issued = 0;

        foreach ($this->gaps($binIndex, max(1, $binCount)) as $enrolment) {
            if ($this->entitlements !== null && $this->entitlements->activeForUserAndCourse(
                $enrolment['user_id'], $enrolment['course_id'],
            ) === []) {
                continue;
            }
            try {
                $result = $this->issue->executeOutcome(
                    $enrolment['id'],
                    $enrolment['user_id'],
                    $enrolment['course_id'],
                    $enrolment['completed_at'],
                );
                if ($result->status === 'issued') {
                    ++$issued;
                }
            } catch (\Throwable) {
                // One enrolment that still refuses is one row, not the run.
                // The next period tries again, which is the whole point of a
                // reconciliation sweep over a one-shot repair.
                continue;
            }
        }

        $this->settings?->set(self::LAST_RUN_SETTING, $now->format('Y-m-d H:i:s'));

        return $issued;
    }

    /**
     * Completed enrolments this bin owns whose certificate is missing.
     *
     * The certificate side is read rather than joined, because the active key
     * is a string column holding an enrolment id and a join on it across two
     * engines is a portability argument nobody wins. The read is bounded by
     * the batch size, so the cost is the batch and not the table.
     *
     * @return list<array{id: int, user_id: int, course_id: int, completed_at: ?\DateTimeImmutable}>
     */
    private function gaps(int $binIndex, int $binCount): array
    {
        $gaps = [];
        $cursor = 0;
        do {
            // Cursor batches bound memory; disabled courses are excluded in
            // SQL so thousands of them cannot occupy every repair batch.
            $rows = $this->database->selectAll(
                Query::from('enrolments', 'e')
                    ->select(['e.id', 'e.user_id', 'e.course_id', 'e.completed_at'])
                    ->leftJoin('course_certificate_policies', 'p', 'e.course_id', 'p.course_id')
                    ->where('e.status', '=', 'completed')->where('e.id', '>', $cursor)
                    ->whereAnyOf(Predicate::isNull('p.id'), Predicate::compare('p.enabled', '=', 1))
                    ->orderBy('e.id', Query::SORT_ASC)->limit(200),
            );
            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                $cursor = $id;

                if ($id < 1 || $id % $binCount !== $binIndex % $binCount) {
                    continue;
                }

                if ($this->hasLiveCertificate($id)) {
                    continue;
                }

                $completedAt = $row['completed_at'] ?? null;

                $gaps[] = [
                'id' => $id,
                'user_id' => (int) ($row['user_id'] ?? 0),
                'course_id' => (int) ($row['course_id'] ?? 0),
                'completed_at' => \is_string($completedAt) && $completedAt !== ''
                    ? new \DateTimeImmutable($completedAt, new \DateTimeZone('UTC'))
                    : null,
                ];

                if (\count($gaps) >= self::BATCH_SIZE) {
                    break 2;
                }
            }
        } while (count($rows) === 200);

        return $gaps;
    }

    /**
     * Whether this enrolment's certificate is accounted for, which is not the
     * same question as whether a row holds the exactly-once key.
     *
     * Revocation clears active_enrolment_key, so a revoked certificate looked
     * exactly like an issuance that failed and this sweep minted a
     * replacement for it within a day, with a new code, a new uuid, its own
     * status list entries and its own frozen credential, verifying as valid.
     * That undoes every revocation on any site with the scheduler running,
     * and it is the opposite of what the sweep is for: the issuance that
     * failed, never the one somebody deliberately withdrew.
     *
     * The key column answers uniqueness and cannot answer "was this
     * withdrawn on purpose", which is the same reason findActiveByEnrolmentId
     * stopped trusting it for expiry.
     */
    private function hasLiveCertificate(int $enrolmentId): bool
    {
        $held = $this->database->count(
            Query::from('certificates')->where('active_enrolment_key', '=', (string) $enrolmentId),
        );

        if ($held > 0) {
            return true;
        }

        return $this->database->count(
            Query::from('certificates')
                ->where('enrolment_id', '=', $enrolmentId)
                ->whereIn('status', [CredentialStatus::Revoked->value, CredentialStatus::Suspended->value]),
        ) > 0;
    }
}
