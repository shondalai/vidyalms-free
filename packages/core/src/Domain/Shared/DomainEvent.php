<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

/**
 * Something that happened in the learning domain, emitted exactly once.
 * Concrete events are small readonly classes named in the past tense
 * (LearnerEnrolled, CourseCompleted, CertificateIssued).
 *
 * The idempotency key identifies the occurrence, not the delivery: adapters
 * and listeners use it to make fan-out safe to retry. Two emissions carrying
 * the same key are the same occurrence and must not produce a second effect.
 */
abstract class DomainEvent
{
    public function __construct(
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * Stable event name in dot notation: "vidyalms.course.completed".
     * Adapters derive their host event names from it.
     */
    abstract public function eventName(): string;

    /**
     * JSON-safe payload for webhooks and host events. Must not leak anything
     * the receiving side is not entitled to (no quiz correctness, no emails
     * of other users).
     *
     * @return array<string, mixed>
     */
    abstract public function payload(): array;

    /**
     * Identifies the occurrence for exactly-once fan-out.
     */
    abstract public function idempotencyKey(): string;
}
