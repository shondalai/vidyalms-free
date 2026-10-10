<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\LessonType;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\LessonType\VideoLessonType;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\PresentsFreePreview;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class VideoLessonTypeTest extends TestCase
{
    private VideoLessonType $type;

    protected function setUp(): void
    {
        $this->type = new VideoLessonType(new StubTranslator());
    }

    public function testIdentity(): void
    {
        self::assertSame('video', $this->type->key());
        self::assertSame('vidyalms.lesson_type.video', $this->type->label());
        self::assertSame(LessonTypeInterface::COMPLETION_ON_VIEW, $this->type->completionMode());
    }

    public function testAcceptsValidContent(): void
    {
        $this->type->validateContent([
            'provider' => 'youtube',
            'url' => 'https://www.youtube.com/watch?v=abc123',
        ]);

        $this->type->validateContent([
            'provider' => 'url',
            'url' => 'https://cdn.example.com/lesson.mp4',
            'caption_url' => 'https://cdn.example.com/lesson.vtt',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testRejectsUnknownProvider(): void
    {
        try {
            $this->type->validateContent(['provider' => 'dailymotion', 'url' => 'https://example.com/v']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('provider', $exception->errors);
        }
    }

    public function testRejectsMissingProvider(): void
    {
        $this->expectException(ValidationException::class);
        $this->type->validateContent(['url' => 'https://example.com/v']);
    }

    public function testRejectsMissingUrl(): void
    {
        try {
            $this->type->validateContent(['provider' => 'vimeo']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('url', $exception->errors);
        }
    }

    public function testRejectsMalformedUrl(): void
    {
        $this->expectException(ValidationException::class);
        $this->type->validateContent(['provider' => 'url', 'url' => 'not a url']);
    }

    public function testRejectsNonHttpScheme(): void
    {
        $this->expectException(ValidationException::class);
        $this->type->validateContent(['provider' => 'url', 'url' => 'ftp://example.com/video.mp4']);
    }

    public function testRejectsMalformedCaptionUrl(): void
    {
        try {
            $this->type->validateContent([
                'provider' => 'youtube',
                'url' => 'https://www.youtube.com/watch?v=abc123',
                'caption_url' => 'nope',
            ]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('caption_url', $exception->errors);
        }
    }

    public function testPresentForLearnerReturnsTheEmbedShapeWithAccess(): void
    {
        $content = [
            'provider' => 'vimeo',
            'url' => 'https://vimeo.com/123',
            'caption_url' => 'https://vimeo.com/123.vtt',
        ];

        self::assertSame($content, $this->type->presentForLearner($content, true));
    }

    /**
     * A video URL is playable by anyone holding it, so the gate has to reach
     * the URL and not only the row it sits in. The provider name survives
     * because it is catalogue metadata rather than content.
     */
    public function testPresentForLearnerWithoutAccessWithholdsTheUrls(): void
    {
        $presented = $this->type->presentForLearner([
            'provider' => 'vimeo',
            'url' => 'https://vimeo.com/123',
            'caption_url' => 'https://vimeo.com/123.vtt',
        ], false);

        self::assertSame([
            'provider' => 'vimeo',
            'url' => null,
            'caption_url' => null,
        ], $presented);
    }

    /**
     * A free-preview video is the video: the author chose to show it, so the
     * preview carries the playable URLs and the delivery overrides an
     * enrolled learner gets.
     */
    public function testThePreviewIsThePlayableLesson(): void
    {
        $content = [
            'provider' => 'url',
            'url' => 'https://cdn.example.test/intro.mp4',
            'caption_url' => 'https://cdn.example.test/intro.vtt',
            'presentation' => ['poster_url' => 'https://cdn.example.test/intro.jpg'],
        ];

        self::assertInstanceOf(PresentsFreePreview::class, $this->type);
        self::assertEquals($this->type->presentForLearner($content, true), $this->type->presentForPreview($content));
        self::assertSame('https://cdn.example.test/intro.mp4', $this->type->presentForPreview($content)['url']);
    }
}
