<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Assignments;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Assignments\Submission;
use Shondalai\VidyaLMS\Core\Domain\Assignments\SubmissionFile;

final class SubmissionTest extends TestCase
{
    public function testSubmittedFactoryStartsInSubmittedState(): void
    {
        $submittedAt = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));
        $submission = Submission::submitted(1, 2, 3, 1, 'answer', $submittedAt, []);

        self::assertNull($submission->id);
        self::assertSame('submitted', $submission->status());
        self::assertTrue($submission->isSubmitted());
        self::assertTrue($submission->isFinalized());
        self::assertSame($submittedAt, $submission->submittedAt);
        self::assertNull($submission->gradedAt());
        self::assertNull($submission->pointsAwarded());
    }

    public function testGradeTransitionsToGradedAndRecordsGradeFields(): void
    {
        $submittedAt = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));
        $gradedAt = $submittedAt->modify('+1 day');
        $submission = Submission::submitted(1, 2, 3, 1, 'answer', $submittedAt, []);

        $submission->grade(7, 88.5, 'Nice', $gradedAt);

        self::assertSame('graded', $submission->status());
        self::assertFalse($submission->isSubmitted());
        self::assertTrue($submission->isFinalized());
        self::assertSame(7, $submission->graderId());
        self::assertEqualsWithDelta(88.5, (float) $submission->pointsAwarded(), 0.001);
        self::assertSame('Nice', $submission->feedback());
        self::assertSame($gradedAt, $submission->gradedAt());
    }

    public function testDraftIsNotFinalized(): void
    {
        $submission = new Submission(1, 1, 2, 3, 1, Submission::STATUS_DRAFT, null, null, null, null, null, null, []);

        self::assertFalse($submission->isFinalized());
        self::assertFalse($submission->isSubmitted());
    }

    public function testWithIdKeepsFilesAndState(): void
    {
        $file = new SubmissionFile('submissions/1/a.pdf', 'a.pdf', 10, 'application/pdf');
        $submittedAt = new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC'));
        $submission = Submission::submitted(1, 2, 3, 4, null, $submittedAt, [$file]);

        $withId = $submission->withId(77);

        self::assertSame(77, $withId->id);
        self::assertSame(4, $withId->attemptNumber);
        self::assertSame([$file], $withId->files);
        self::assertSame('submitted', $withId->status());
    }

    public function testFileExtensionExtraction(): void
    {
        self::assertSame('pdf', (new SubmissionFile('submissions/a', 'Report.PDF', 1, 'x'))->extension());
        self::assertSame('gz', (new SubmissionFile('submissions/a', 'archive.tar.gz', 1, 'x'))->extension());
        self::assertSame('', (new SubmissionFile('submissions/a', 'README', 1, 'x'))->extension());
        self::assertSame('', (new SubmissionFile('submissions/a', 'trailing.', 1, 'x'))->extension());
    }
}
