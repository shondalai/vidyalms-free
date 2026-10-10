<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Assessment;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Assessment\Attempt;
use Shondalai\VidyaLMS\Core\Domain\Assessment\AttemptRepository;
use Shondalai\VidyaLMS\Core\Domain\Assessment\IntegrityEvent;
use Shondalai\VidyaLMS\Core\Domain\Assessment\IntegrityEventRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Registry\Integrity\IntegrityEventKindInterface;
use Shondalai\VidyaLMS\Core\Registry\Integrity\IntegrityEventKindRegistry;

/**
 * Records one advisory integrity signal against the caller's own attempt.
 *
 * Everything about this use case is a refusal to do more than record.
 * Nothing here reads an answer, moves a score, changes an attempt status or
 * raises an event other packages could subscribe to, and there is no code
 * path by which a signal could. That is not caution: a blur is as likely to
 * be a docked magnifier, an on-screen keyboard or a read-aloud application
 * as a second window, and a product that acted on one would be punishing the
 * learners the accessibility work exists to serve.
 *
 * For the same reason, recording is suppressed entirely when the attempt's
 * frozen accessibility profile declares one of the five needs whose tooling
 * blurs the browser. Suppressed, not recorded and discounted: a row that
 * exists is evidence a human will read, and the answer says the recording
 * was suppressed so the client stops reporting rather than retrying.
 */
final class RecordIntegrityEvent
{
    /**
     * Advisory detail is capped rather than trusted. The column is JSON and
     * the client fills it, so an uncapped context is a client-controlled blob
     * on an append-only table nobody prunes.
     */
    private const MAX_CONTEXT_BYTES = 2000;

    /**
     * How many signals one attempt may record in total, and how many of any
     * one kind.
     *
     * The only other gates are that the caller owns the attempt, the attempt
     * accepts answers and the kind resolves, so a learner could post signals
     * in a loop for the length of their own attempt onto an append-only
     * table nobody prunes. Nothing reads these rows in a decision path, so
     * this is storage growth rather than an enforcement risk, which is why
     * reaching the cap answers "suppressed" rather than an error: the client
     * stops reporting instead of retrying, which is the shape the
     * accessibility suppression path already uses.
     */
    public const MAX_EVENTS_PER_ATTEMPT = 500;

    public const MAX_EVENTS_PER_KIND = 200;

    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly IntegrityEventRepository $events,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
        /**
         * The kinds this site records. Optional and last so an adapter built
         * before the registry existed keeps constructing this use case; with
         * none, the free vocabulary and the vendor-namespace rule stand on
         * their own exactly as they did.
         */
        private readonly ?IntegrityEventKindRegistry $kinds = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $context
     */
    public function execute(
        Principal $principal,
        int $attemptId,
        string $kind,
        ?\DateTimeImmutable $occurredAt = null,
        ?array $context = null,
    ): IntegrityEventOutcome {
        $this->guard->requireAuthenticated($principal);

        $attempt = $this->attempts->byId($attemptId)
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw EntityNotFoundException::for('Attempt', $attemptId);

        if (!$attempt->isOwnedBy($principal->userId)) {
            throw new NotAuthorizedException('Only the owner of an attempt may report on it.');
        }

        if (!$attempt->acceptsAnswers()) {
            throw new ConflictException('Integrity signals are only recorded while the attempt is in progress.');
        }

        $resolved = $this->kinds !== null && $this->kinds->has($kind) ? $this->kinds->get($kind) : null;

        // A registered kind is authoritative; the free vocabulary and the
        // vendor namespace rule remain for a build whose adapter has not
        // handed the registry over. An unknown kind is refused either way,
        // because an audit table that accepts any string a client sends is
        // not an audit table.
        if ($resolved === null && !IntegrityEvent::isKnownKind($kind)) {
            throw ValidationException::forField('kind', 'Unknown integrity signal kind.');
        }

        $this->requireSmallContext($context);

        // The kind's own allowlist, so a client cannot write arbitrary JSON
        // into a column a human reads as evidence. A kind with no provider
        // keeps the capped raw context, which is what the free build did
        // before the registry existed.
        $narrowed = $resolved === null ? $context : $resolved->normalise($context ?? []);

        if ($this->suppressed($attempt, $resolved)) {
            return IntegrityEventOutcome::suppressed();
        }

        if ($this->atCap($attemptId, $kind)) {
            return IntegrityEventOutcome::suppressed();
        }

        $now = $this->clock->now();

        return IntegrityEventOutcome::recorded($this->events->add(new IntegrityEvent(
            null,
            $attemptId,
            $kind,
            // The client's clock is advisory. Anything outside the attempt's
            // own window is clamped to it, so a wrong clock cannot write
            // evidence dated before the attempt started or after now.
            $this->clamp($occurredAt ?? $now, $attempt->startedAt, $now),
            $narrowed === [] ? null : $narrowed,
        )));
    }

    /**
     * Suppression is the kind's own declaration crossed with the attempt's
     * frozen profile.
     *
     * A proctoring integration that observed something the browser cannot
     * see says so, and is not silently switched off for the learners it was
     * bought for.
     */
    private function suppressed(Attempt $attempt, ?IntegrityEventKindInterface $kind): bool
    {
        if (!$attempt->policy()->suppressesIntegrityAutomation()) {
            return false;
        }

        return $kind === null || $kind->suppressedByAccessibilityProfile();
    }

    private function atCap(int $attemptId, string $kind): bool
    {
        $counts = $this->events->countsForAttempt($attemptId);

        if (array_sum($counts) >= self::MAX_EVENTS_PER_ATTEMPT) {
            return true;
        }

        return ($counts[$kind] ?? 0) >= self::MAX_EVENTS_PER_KIND;
    }

    private function clamp(
        \DateTimeImmutable $observed,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $now,
    ): \DateTimeImmutable {
        if ($observed->getTimestamp() < $startedAt->getTimestamp()) {
            return $startedAt;
        }

        return $observed->getTimestamp() > $now->getTimestamp() ? $now : $observed;
    }

    /**
     * @param array<string, mixed>|null $context
     */
    private function requireSmallContext(?array $context): void
    {
        if ($context === null) {
            return;
        }

        $encoded = json_encode($context);

        if ($encoded === false || \strlen($encoded) > self::MAX_CONTEXT_BYTES) {
            throw ValidationException::forField(
                'context',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('The advisory context must encode to at most %d bytes.', self::MAX_CONTEXT_BYTES),
            );
        }
    }
}
