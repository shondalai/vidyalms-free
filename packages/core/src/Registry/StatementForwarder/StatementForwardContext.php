<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\StatementForwarder;

/**
 * What a forwarder is told about the statement it was handed.
 *
 * The ids travel beside the document because a warehouse mapper wants them as
 * columns and an LRS mapper does not, and neither should have to reach into
 * the statement's JSON to find them.
 */
final class StatementForwardContext
{
    private function __construct(
        public readonly string $eventName,
        public readonly int $attemptId,
        public readonly int $quizId,
        public readonly int $courseId,
        public readonly int $userId,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }

    public static function create(
        string $eventName,
        int $attemptId,
        int $quizId,
        int $courseId,
        int $userId,
        \DateTimeImmutable $occurredAt,
    ): self {
        return new self($eventName, $attemptId, $quizId, $courseId, $userId, $occurredAt);
    }
}
