<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Events;

use Shondalai\VidyaLMS\Core\Domain\Assessment\Reporting\XapiStatement;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainEvent;

/**
 * One xAPI statement the core built for an attempt.
 *
 * The free build's obligation stops here: it records what a conformant
 * emitter must send. Forwarding to an LRS is a paid concern and is a
 * listener on this event, so no core code holds an endpoint, a credential or
 * a queue.
 *
 * The idempotency key is the statement id, which is a deterministic UUID
 * over the occurrence rather than a random one, so rebuilding the same
 * statement is the same occurrence and an LRS that deduplicates on statement
 * id stores it once.
 */
final class XapiStatementBuilt extends DomainEvent
{
    public function __construct(
        \DateTimeImmutable $occurredAt,
        public readonly int $attemptId,
        public readonly int $quizId,
        public readonly int $courseId,
        public readonly int $userId,
        public readonly XapiStatement $statement,
    ) {
        parent::__construct($occurredAt);
    }

    public function eventName(): string
    {
        return 'vidyalms.xapi.statement_built';
    }

    public function payload(): array
    {
        return [
            'attempt_id' => $this->attemptId,
            'quiz_id' => $this->quizId,
            'course_id' => $this->courseId,
            'user_id' => $this->userId,
            'statement_id' => $this->statement->id,
            'verb' => $this->statement->verb,
            'question_id' => $this->statement->questionId,
            'statement' => $this->statement->toArray(),
        ];
    }

    public function idempotencyKey(): string
    {
        return 'xapi.statement:' . $this->statement->id;
    }
}
