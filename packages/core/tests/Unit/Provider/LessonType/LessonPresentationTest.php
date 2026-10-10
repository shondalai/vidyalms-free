<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\LessonType;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\LessonType\LessonPresentation;
use Shondalai\VidyaLMS\Core\Provider\LessonType\VideoLessonType;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class LessonPresentationTest extends TestCase
{
    public function testDeliveryContentIsWithheldWithoutAccessAndUnknownFieldsAreNotExposed(): void
    {
        $content = ['provider' => 'url', 'url' => 'https://example.test/course.mp4', 'presentation' => [
            'transcript' => 'Private transcript', 'poster_url' => 'https://example.test/cover.jpg',
            'provider_secret' => 'must not be returned', 'resume' => false,
        ]];
        $type = new VideoLessonType(new StubTranslator());
        $type->validateContent($content);
        self::assertArrayNotHasKey('presentation', $type->presentForLearner($content, false));
        $presented = (array) $type->presentForLearner($content, true)['presentation'];
        self::assertSame('Private transcript', $presented['transcript']);
        self::assertFalse($presented['resume']);
        self::assertArrayNotHasKey('provider_secret', $presented);
    }

    public function testRejectsUnsafeCaptionUrls(): void
    {
        $this->expectException(ValidationException::class);
        LessonPresentation::validate(['presentation' => ['captions' => [
            ['language' => 'en', 'label' => 'English', 'url' => 'javascript:alert(1)'],
        ]]], 'video');
    }

    public function testRejectsStringBooleans(): void
    {
        $this->expectException(ValidationException::class);
        LessonPresentation::validate(['presentation' => ['resume' => 'false']], 'video');
    }

    public function testRejectsUnknownReadingWidths(): void
    {
        $this->expectException(ValidationException::class);
        LessonPresentation::validate(['presentation' => ['reading_width' => 'giant']], 'reading');
    }
}
