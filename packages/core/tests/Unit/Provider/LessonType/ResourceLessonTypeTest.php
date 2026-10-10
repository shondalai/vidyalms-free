<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Provider\LessonType;

use PHPUnit\Framework\Attributes\DataProviderExternal;
use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Provider\LessonType\ResourceLessonType;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeInterface;
use Shondalai\VidyaLMS\Core\Registry\LessonType\PresentsFreePreview;
use Shondalai\VidyaLMS\Core\Tests\Support\ResourcePathCases;
use Shondalai\VidyaLMS\Core\Tests\Support\StubTranslator;

final class ResourceLessonTypeTest extends TestCase
{
    private ResourceLessonType $type;

    protected function setUp(): void
    {
        $this->type = new ResourceLessonType(new StubTranslator());
    }

    public function testIdentity(): void
    {
        self::assertSame('resource', $this->type->key());
        self::assertSame('vidyalms.lesson_type.resource', $this->type->label());
        self::assertSame(LessonTypeInterface::COMPLETION_ON_VIEW, $this->type->completionMode());
    }

    public function testAcceptsValidFiles(): void
    {
        $this->type->validateContent([
            'files' => [
                ['path' => 'lesson-files/1/workbook.pdf', 'name' => 'Workbook.pdf', 'size_bytes' => 1024],
                ['path' => 'lesson-files/1/slides.pdf', 'name' => 'Slides.pdf'],
            ],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testRejectsMissingFiles(): void
    {
        try {
            $this->type->validateContent([]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('files', $exception->errors);
        }
    }

    #[DataProviderExternal(ResourcePathCases::class, 'authored')]
    public function testAcceptsEveryAuthoredNamespace(string $path): void
    {
        $this->type->validateContent(['files' => [['path' => $path, 'name' => 'Handout']]]);
        $this->addToAssertionCount(1);
    }

    #[DataProviderExternal(ResourcePathCases::class, 'forbidden')]
    public function testRejectsNonAuthoredPathsAtTheCorrectIndex(string $path): void
    {
        try {
            $this->type->validateContent(['files' => [
                ['path' => 'lesson-files/handout.pdf', 'name' => 'Allowed'],
                ['path' => $path, 'name' => 'Forbidden'],
            ]]);
            self::fail('Expected an invalid resource path to be refused.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('files.1.path', $exception->errors);
        }
    }

    public function testRejectsEmptyFileList(): void
    {
        $this->expectException(ValidationException::class);
        $this->type->validateContent(['files' => []]);
    }

    public function testRejectsFileWithoutPath(): void
    {
        try {
            $this->type->validateContent(['files' => [['name' => 'Workbook.pdf']]]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('files.0.path', $exception->errors);
        }
    }

    public function testRejectsFileWithoutName(): void
    {
        try {
            $this->type->validateContent(['files' => [['path' => 'lesson-files/1/a.pdf']]]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('files.0.name', $exception->errors);
        }
    }

    public function testRejectsNegativeSize(): void
    {
        $this->expectException(ValidationException::class);
        $this->type->validateContent([
            'files' => [['path' => 'lesson-files/1/a.pdf', 'name' => 'a.pdf', 'size_bytes' => -1]],
        ]);
    }

    public function testPresentForLearnerIncludesPathsForLearnersWithAccess(): void
    {
        $presented = $this->type->presentForLearner([
            'files' => [['path' => 'lesson-files/1/workbook.pdf', 'name' => 'Workbook.pdf', 'size_bytes' => 1024]],
        ], true);

        self::assertSame([
            'files' => [['name' => 'Workbook.pdf', 'size_bytes' => 1024, 'path' => 'lesson-files/1/workbook.pdf']],
        ], $presented);
    }

    public function testPresentForLearnerStripsPathsWithoutAccess(): void
    {
        $presented = $this->type->presentForLearner([
            'files' => [
                ['path' => 'lesson-files/1/workbook.pdf', 'name' => 'Workbook.pdf', 'size_bytes' => 1024],
                ['path' => 'lesson-files/1/slides.pdf', 'name' => 'Slides.pdf'],
            ],
        ], false);

        self::assertSame([
            'files' => [
                ['name' => 'Workbook.pdf', 'size_bytes' => 1024],
                ['name' => 'Slides.pdf', 'size_bytes' => null],
            ],
        ], $presented);

        self::assertStringNotContainsString(
            'courses/1',
            json_encode($presented, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * A previewed resource is downloadable. The path goes to the lesson
     * route exactly as it does for an enrolled learner, and the route swaps
     * it for a signed, expiring URL, so the raw path still never reaches a
     * response.
     */
    public function testThePreviewCarriesTheFilesAsAnEnrolledLearnerGetsThem(): void
    {
        $content = [
            'files' => [['path' => 'lesson-files/1/workbook.pdf', 'name' => 'Workbook.pdf', 'size_bytes' => 1024]],
            'presentation' => ['show_file_size' => true],
        ];

        self::assertInstanceOf(PresentsFreePreview::class, $this->type);
        self::assertEquals($this->type->presentForLearner($content, true), $this->type->presentForPreview($content));
    }
}
