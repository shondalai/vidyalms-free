<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Assessment;

/**
 * The server-side deadline of an attempt.
 *
 * **It is derived from two things and frozen on the attempt row, and the
 * frozen value wins.** The deadline is the earlier of started_at plus the
 * effective time limit and the quiz's closes_at. Deriving it from both is
 * what makes changing a close time change live attempts consistently;
 * freezing it is what stops a shortened time limit expiring an attempt
 * already in flight. Recomputing it from the live quiz on every read once
 * handed a learner twenty minutes into a one hour attempt a deadline fifteen
 * minutes in the past and refused the next save, mid question, which
 * contradicts the rule that an attempt carries the policy it started under.
 *
 * The computed form remains for the two callers that legitimately have no
 * frozen value: StartAttempt itself, and an attempt written before the
 * column was populated.
 *
 * The grace beyond the deadline absorbs clock skew and slow submits. It is
 * per quiz, with 60 seconds as the floor rather than merely the default,
 * because below that a submit that left the browser in time would be
 * refused. Saves are refused past deadline plus grace; a submission arriving
 * after it still succeeds and grades only what was stored in time.
 *
 * Accessibility (WCAG 2.2.1): a timed exam is an essential-exception time
 * limit, and the route the Understanding document requires is an authorised
 * extension, which arrives as the effective limit written onto the attempt
 * rather than as a client-side allowance; clients display expires_at as an
 * advisory timer synchronised to this server deadline instead of trusting
 * their own clock.
 */
final class AttemptDeadline
{
    public const GRACE_SECONDS = QuizPolicy::MIN_GRACE_SECONDS;

    private function __construct()
    {
    }

    /**
     * The deadline of a starting attempt: the earlier of the time limit and
     * the quiz's close time, or null when neither bounds it.
     */
    public static function derive(
        ?int $timeLimitSeconds,
        ?\DateTimeImmutable $closesAt,
        \DateTimeImmutable $startedAt,
    ): ?\DateTimeImmutable {
        $limit = $timeLimitSeconds === null
            ? null
            : $startedAt->modify(sprintf('+%d seconds', $timeLimitSeconds));

        if ($limit === null) {
            return $closesAt;
        }

        if ($closesAt === null) {
            return $limit;
        }

        return $closesAt->getTimestamp() < $limit->getTimestamp() ? $closesAt : $limit;
    }

    /**
     * The time-limit half alone, kept for the callers that have a limit and
     * no quiz policy in hand.
     */
    public static function expiresAt(?int $timeLimitSeconds, \DateTimeImmutable $startedAt): ?\DateTimeImmutable
    {
        return self::derive($timeLimitSeconds, null, $startedAt);
    }

    /**
     * The deadline one in-flight attempt actually runs under: the frozen
     * column when the row has one, and the derived value only for a row
     * written before the column was populated.
     */
    public static function of(Attempt $attempt, ?int $timeLimitSeconds, ?QuizPolicy $policy = null): ?\DateTimeImmutable
    {
        if ($attempt->expiresAt !== null) {
            return $attempt->expiresAt;
        }

        // **A frozen null is an answer, not a gap.** An attempt started on a
        // quiz with neither a time limit nor a close time froze expires_at
        // null, and every later read recomputed it from the live quiz: an
        // author who added a time limit while that attempt was open handed
        // the learner a deadline already in the past, and the next save
        // answered 409. The snapshot's flag is what tells the two cases
        // apart, so the derived fallback is left to rows written before the
        // column was populated, which is what this method's docblock has
        // always said it was for.
        if ($attempt->policy()->deadlineResolved()) {
            return null;
        }

        return self::derive($timeLimitSeconds, $policy?->closesAt, $attempt->startedAt);
    }

    /**
     * True once "now" lies past deadline plus grace; always false when
     * nothing bounds the attempt.
     */
    public static function hasExpired(
        ?int $timeLimitSeconds,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $now,
        int $graceSeconds = self::GRACE_SECONDS,
    ): bool {
        return self::isPast(self::expiresAt($timeLimitSeconds, $startedAt), $now, $graceSeconds);
    }

    /**
     * The same question asked about an attempt, so the frozen deadline is
     * what a late save is measured against, and the quiz's own grace is what
     * it is measured with.
     */
    public static function hasExpiredFor(
        Attempt $attempt,
        ?int $timeLimitSeconds,
        \DateTimeImmutable $now,
        ?QuizPolicy $policy = null,
    ): bool {
        return self::isPast(
            self::of($attempt, $timeLimitSeconds, $policy),
            $now,
            $policy === null ? self::GRACE_SECONDS : $policy->graceSeconds,
        );
    }

    /**
     * True once the deadline itself has passed, grace or no grace. That is
     * the moment the attempt becomes overdue: the answer window closes at
     * the deadline and only the submit lives on into the grace.
     */
    public static function isOverdue(
        Attempt $attempt,
        ?int $timeLimitSeconds,
        \DateTimeImmutable $now,
        ?QuizPolicy $policy = null,
    ): bool {
        return self::isPast(self::of($attempt, $timeLimitSeconds, $policy), $now, 0);
    }

    private static function isPast(?\DateTimeImmutable $expiresAt, \DateTimeImmutable $now, int $graceSeconds): bool
    {
        if ($expiresAt === null) {
            return false;
        }

        return $now->getTimestamp() > $expiresAt->getTimestamp() + max(0, $graceSeconds);
    }
}
