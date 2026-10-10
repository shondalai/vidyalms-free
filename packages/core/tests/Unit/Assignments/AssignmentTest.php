<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Assignments;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Assignment;

final class AssignmentTest extends TestCase
{
    /**
     * @param list<string> $allowedFileTypes
     */
    private function assignment(
        ?\DateTimeImmutable $dueAt = null,
        array $allowedFileTypes = [],
        ?int $maxFileSizeKb = null,
    ): Assignment {
        return new Assignment(1, 5, null, 100.0, $allowedFileTypes, $maxFileSizeKb, 1, $dueAt, false);
    }

    public function testNoDueDateIsNeverOverdue(): void
    {
        $moment = new \DateTimeImmutable('2030-01-01 00:00:00', new \DateTimeZone('UTC'));

        self::assertFalse($this->assignment()->isOverdueAt($moment));
    }

    public function testMomentAfterDueDateIsOverdue(): void
    {
        $due = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));

        self::assertTrue($this->assignment($due)->isOverdueAt($due->modify('+1 second')));
    }

    public function testMomentAtDueDateIsNotOverdue(): void
    {
        $due = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));

        self::assertFalse($this->assignment($due)->isOverdueAt($due));
    }

    public function testMomentBeforeDueDateIsNotOverdue(): void
    {
        $due = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));

        self::assertFalse($this->assignment($due)->isOverdueAt($due->modify('-1 hour')));
    }

    public function testEmptyAllowedListAcceptsAnyExtension(): void
    {
        self::assertTrue($this->assignment()->acceptsExtension('exe'));
        self::assertTrue($this->assignment()->acceptsExtension(''));
    }

    public function testExtensionCheckIsCaseInsensitive(): void
    {
        $assignment = $this->assignment(allowedFileTypes: ['pdf', 'docx']);

        self::assertTrue($assignment->acceptsExtension('PDF'));
        self::assertTrue($assignment->acceptsExtension('docx'));
        self::assertFalse($assignment->acceptsExtension('exe'));
        self::assertFalse($assignment->acceptsExtension(''));
    }

    public function testMaxFileSizeConvertsToBytes(): void
    {
        self::assertNull($this->assignment()->maxFileSizeBytes());
        self::assertSame(2048, $this->assignment(maxFileSizeKb: 2)->maxFileSizeBytes());
    }

    public function testWithIdKeepsAllOtherFields(): void
    {
        $due = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));
        $original = new Assignment(null, 5, 'Do it.', 40.0, ['pdf'], 100, 2, $due, true);

        $withId = $original->withId(9);

        self::assertSame(9, $withId->id);
        self::assertSame(5, $withId->lessonId);
        self::assertSame('Do it.', $withId->instructions);
        self::assertEqualsWithDelta(40.0, $withId->maxPoints, 0.001);
        self::assertSame(['pdf'], $withId->allowedFileTypes);
        self::assertSame(100, $withId->maxFileSizeKb);
        self::assertSame(2, $withId->maxFiles);
        self::assertSame($due, $withId->dueAt);
        self::assertTrue($withId->allowResubmission);
    }
}
