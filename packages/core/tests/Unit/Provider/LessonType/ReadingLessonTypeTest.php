<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\LessonType;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\LessonType\ReadingLessonType;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\PresentsFreePreview;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class ReadingLessonTypeTest extends TestCase
{
    private ReadingLessonType $type;

    protected function setUp(): void
    {
        $this->type = new ReadingLessonType(new StubTranslator());
    }

    public function testIdentity(): void
    {
        self::assertSame('reading', $this->type->key());
        self::assertSame('vidyalms.lesson_type.reading', $this->type->label());
        self::assertSame(LessonTypeInterface::COMPLETION_ON_VIEW, $this->type->completionMode());
    }

    public function testAcceptsABody(): void
    {
        $this->type->validateContent(['body' => '<p>Welcome to the course.</p>']);
        $this->addToAssertionCount(1);
    }

    public function testRejectsMissingBody(): void
    {
        try {
            $this->type->validateContent([]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('body', $exception->errors);
        }
    }

    public function testRejectsEmptyBody(): void
    {
        $this->expectException(ValidationException::class);
        $this->type->validateContent(['body' => '   ']);
    }

    public function testRejectsNonStringBody(): void
    {
        $this->expectException(ValidationException::class);
        $this->type->validateContent(['body' => ['not', 'a', 'string']]);
    }

    public function testPresentForLearnerReturnsTheBody(): void
    {
        $presented = $this->type->presentForLearner(['body' => '<p>Hi</p>'], true);

        self::assertSame(['body' => '<p>Hi</p>'], $presented);
    }

    public function testPresentForLearnerWithoutAccessWithholdsTheBody(): void
    {
        self::assertSame(['body' => null], $this->type->presentForLearner(['body' => '<p>Hi</p>'], false));
    }

    /**
     * The body is the lesson, so a free preview that withheld it would be a
     * preview of nothing: it said "Nothing to read yet" to every visitor.
     */
    public function testThePreviewIsTheBody(): void
    {
        $content = ['body' => '<p>Hi</p>', 'presentation' => ['reading_width' => 'wide']];

        self::assertInstanceOf(PresentsFreePreview::class, $this->type);
        self::assertEquals($this->type->presentForLearner($content, true), $this->type->presentForPreview($content));
        self::assertSame('<p>Hi</p>', $this->type->presentForPreview($content)['body']);
    }
}
