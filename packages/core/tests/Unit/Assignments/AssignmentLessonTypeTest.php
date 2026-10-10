<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Assignments;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\LessonType\AssignmentLessonType;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\PresentsFreePreview;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class AssignmentLessonTypeTest extends TestCase
{
    private function lessonType(): AssignmentLessonType
    {
        return new AssignmentLessonType(new StubTranslator());
    }

    public function testKeyIsAssignment(): void
    {
        self::assertSame('assignment', $this->lessonType()->key());
    }

    public function testLabelComesFromTheTranslator(): void
    {
        self::assertSame('vidyalms.lesson_type.assignment', $this->lessonType()->label());
    }

    public function testCompletionModeIsOnSubmission(): void
    {
        self::assertSame(LessonTypeInterface::COMPLETION_ON_SUBMISSION, $this->lessonType()->completionMode());
    }

    public function testEmptyContentIsValid(): void
    {
        $this->lessonType()->validateContent([]);
        $this->lessonType()->validateContent(['instructions' => null]);
        $this->lessonType()->validateContent(['instructions' => 'Write an essay.']);

        $this->addToAssertionCount(1);
    }

    public function testNonStringInstructionsAreRejected(): void
    {
        try {
            $this->lessonType()->validateContent(['instructions' => 42]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('instructions', $exception->errors);
        }
    }

    public function testPresentForLearnerPassesInstructionsThrough(): void
    {
        $presented = $this->lessonType()->presentForLearner(['instructions' => 'Do the thing.'], true);

        self::assertSame(['instructions' => 'Do the thing.'], $presented);
    }

    public function testPresentForLearnerWithoutInstructionsYieldsNull(): void
    {
        self::assertSame(['instructions' => null], $this->lessonType()->presentForLearner([], false));
    }

    public function testPresentForLearnerDropsUnknownKeys(): void
    {
        $presented = $this->lessonType()->presentForLearner(
            ['instructions' => 'Read.', 'secret' => 'grading rubric'],
            true,
        );

        self::assertSame(['instructions' => 'Read.'], $presented);
    }

    /**
     * The brief is the content of a gated assignment, so a reader the gate
     * refused does not get it. The type ignored the flag, so the
     * instructions crossed the boundary beside an access block saying the
     * item was blocked.
     */
    public function testPresentForLearnerWithoutAccessWithholdsTheInstructions(): void
    {
        $presented = $this->lessonType()->presentForLearner(
            ['instructions' => 'Read.', 'secret' => 'grading rubric'],
            false,
        );

        self::assertSame(['instructions' => null], $presented);
    }

    /**
     * The decision in 13-learning-flow-and-credentials.md: an assignment its
     * author marked free preview shows its brief, because marking it was
     * the disclosure and nothing gradable lives in lesson content. Unknown
     * keys are still dropped, so a preview never carries more than an
     * enrolled learner sees.
     */
    public function testThePreviewShowsTheBriefAndNothingElse(): void
    {
        $content = ['instructions' => 'Read.', 'secret' => 'grading rubric'];

        self::assertInstanceOf(PresentsFreePreview::class, $this->lessonType());
        self::assertSame(['instructions' => 'Read.'], $this->lessonType()->presentForPreview($content));
    }
}
