<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Grades;

/**
 * One entry in the append-only grade change log: before, after, actor, reason
 * and request reference.
 *
 * Canvas keeps a `GradeChangeEvent` queryable per assignment, course, student
 * and grader over a time range; Moodle keeps `grade_grades_history`. Both exist
 * for one reason, which is that somebody will eventually dispute a grade, and a
 * total with no history is one person's word against another's. The append-only
 * event with an idempotency key is already the house pattern for entitlements
 * and attempt answers; grades earn it on the same argument.
 *
 * **A machine regrade has a null actor and that is a fact rather than a gap.**
 * Stamping a system user id would make an automatic recalculation
 * indistinguishable from something an administrator did, which is the one
 * distinction a dispute turns on.
 *
 * **The reason is required of a human and optional of a machine.** A regrade
 * somebody made without saying why is the row that costs the most to read back
 * two years later, so {@see byPerson()} refuses one.
 */
final class GradeChange
{
    /** The first score a cell ever carried. */
    public const REASON_FIRST_GRADE = 'first_grade';

    /** An attempt or a submission was graded again by the shipped path. */
    public const REASON_REGRADE = 'regrade';

    /** The shape this build writes for the stored payload. */
    public const PAYLOAD_SHAPE = 1;

    /**
     * @param array<string, mixed> $payload the body, without its version key
     */
    public function __construct(
        public readonly ?int $id,
        public readonly int $gradeCellId,
        public readonly int $gradeItemId,
        public readonly int $courseId,
        public readonly int $userId,
        public readonly ?float $gradeBefore,
        public readonly ?float $gradeAfter,
        public readonly ?float $maxBefore,
        public readonly ?float $maxAfter,
        public readonly ?string $statusBefore,
        public readonly ?string $statusAfter,
        public readonly ?int $actorId,
        public readonly ?string $reason,
        public readonly ?string $requestRef,
        public readonly array $payload,
        public readonly int $payloadVersion,
        public readonly \DateTimeImmutable $occurredAt,
    ) {
    }

    /**
     * A change a person made, which must say why.
     *
     * @param array<string, mixed> $payload
     */
    public static function byPerson(
        int $gradeCellId,
        int $gradeItemId,
        int $courseId,
        int $userId,
        int $actorId,
        string $reason,
        \DateTimeImmutable $occurredAt,
        ?float $gradeBefore = null,
        ?float $gradeAfter = null,
        ?float $maxBefore = null,
        ?float $maxAfter = null,
        ?string $statusBefore = null,
        ?string $statusAfter = null,
        ?string $requestRef = null,
        array $payload = [],
    ): self {
        if (trim($reason) === '') {
            throw new \InvalidArgumentException(
                'A person changing a grade says why. The reason is what a dispute is read back through.',
            );
        }

        if ($actorId < 1) {
            throw new \InvalidArgumentException('A change by a person names the person.');
        }

        return new self(
            null,
            $gradeCellId,
            $gradeItemId,
            $courseId,
            $userId,
            $gradeBefore,
            $gradeAfter,
            $maxBefore,
            $maxAfter,
            $statusBefore,
            $statusAfter,
            $actorId,
            trim($reason),
            $requestRef,
            $payload,
            self::PAYLOAD_SHAPE,
            $occurredAt,
        );
    }

    /**
     * A change the product made: an attempt graded, a submission marked, a
     * recalculation.
     *
     * @param array<string, mixed> $payload
     */
    public static function bySystem(
        int $gradeCellId,
        int $gradeItemId,
        int $courseId,
        int $userId,
        string $reason,
        \DateTimeImmutable $occurredAt,
        ?float $gradeBefore = null,
        ?float $gradeAfter = null,
        ?float $maxBefore = null,
        ?float $maxAfter = null,
        ?string $statusBefore = null,
        ?string $statusAfter = null,
        ?string $requestRef = null,
        array $payload = [],
    ): self {
        return new self(
            null,
            $gradeCellId,
            $gradeItemId,
            $courseId,
            $userId,
            $gradeBefore,
            $gradeAfter,
            $maxBefore,
            $maxAfter,
            $statusBefore,
            $statusAfter,
            null,
            $reason,
            $requestRef,
            $payload,
            self::PAYLOAD_SHAPE,
            $occurredAt,
        );
    }

    /** Whether a person made this change, which is the question a dispute asks first. */
    public function wasMadeByAPerson(): bool
    {
        return $this->actorId !== null;
    }
}
