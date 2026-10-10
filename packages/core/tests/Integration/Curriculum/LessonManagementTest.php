<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use Shondalai\VidyaLMS\Core\Application\Curriculum\AddLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\LessonAvailability;
use Shondalai\VidyaLMS\Core\Application\Curriculum\ReorderLessons;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateLesson;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Port\Database\Query;
use Shondalai\VidyaLMS\Core\Provider\LessonType\ReadingLessonType;
use Shondalai\VidyaLMS\Core\Provider\LessonType\VideoLessonType;
use Shondalai\VidyaLMS\Core\Registry\Shape\StoredShape;

final class LessonManagementTest extends CurriculumTestCase
{
    private function addLesson(): AddLesson
    {
        return new AddLesson($this->lessons, $this->modules, $this->access, $this->lessonTypes);
    }

    private function updateLesson(): UpdateLesson
    {
        return new UpdateLesson(
            $this->lessons,
            $this->access,
            $this->lessonTypes,
            new LessonAvailability($this->env->database),
        );
    }

    public function testAVideoCanBeDraftedButCannotBePublishedWithoutADuration(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $module = $this->newModule($teacher, $course->id);
        $lesson = $this->addLesson()->execute($teacher, $module->id, 'Video draft', 'video',
            ['provider' => 'url', 'url' => 'https://example.test/lesson.mp4'], state: 'draft');
        try {
            $this->updateLesson()->execute($teacher, $lesson->id, ['state' => 'published']);
            self::fail('Publishing a video with no completion duration must fail.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('duration_seconds', $error->errors);
        }
        $published = $this->updateLesson()->execute($teacher, $lesson->id, ['state' => 'published', 'duration_seconds' => 60]);
        self::assertSame('published', $published->state);
        self::assertSame(60, $published->durationSeconds);
    }

    public function testAddLessonStoresTheRowAndItsContentAtomically(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $module = $this->newModule($teacher, $course->id);

        $lesson = $this->addLesson()->execute(
            $teacher,
            $module->id,
            'Intro Video',
            VideoLessonType::KEY,
            ['provider' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=abc'],
            durationSeconds: 300,
            freePreview: true,
        );

        self::assertGreaterThan(0, $lesson->id);
        self::assertSame($course->id, $lesson->courseId);
        self::assertSame('intro-video', $lesson->slug->value);
        self::assertSame(1, $lesson->position);
        self::assertTrue($lesson->freePreview);

        $contentRow = $this->env->database->selectOne(
            Query::from('lesson_content')->where('lesson_id', '=', $lesson->id),
        );
        self::assertNotNull($contentRow);

        $stored = json_decode((string) $contentRow['content'], true);
        self::assertIsArray($stored);

        // The stored payload carries the provider's shape version; nothing
        // above the repository ever sees it.
        self::assertSame(1, StoredShape::versionOf($stored));
        self::assertSame(
            ['provider' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=abc'],
            StoredShape::bodyOf($stored),
        );
        self::assertSame(
            ['provider' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=abc'],
            $this->lessons->contentOf($lesson->id),
        );
    }

    public function testUnknownLessonTypeIsRejected(): void
    {
        $teacher = $this->teacher();
        $module = $this->newModule($teacher, $this->newCourse($teacher)->id);

        try {
            $this->addLesson()->execute($teacher, $module->id, 'SCORM Package', 'scorm', []);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('type', $exception->errors);
        }
    }

    public function testInvalidContentIsRejectedAndNothingIsStored(): void
    {
        $teacher = $this->teacher();
        $module = $this->newModule($teacher, $this->newCourse($teacher)->id);

        try {
            $this->addLesson()->execute($teacher, $module->id, 'Broken Video', VideoLessonType::KEY, ['provider' => 'youtube']);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('url', $exception->errors);
        }

        self::assertSame(0, $this->env->database->count(Query::from('lessons')));
        self::assertSame(0, $this->env->database->count(Query::from('lesson_content')));
    }

    public function testLessonSlugsAreUniquePerCourseWithSuffixes(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $moduleA = $this->newModule($teacher, $course->id, 'A');
        $moduleB = $this->newModule($teacher, $course->id, 'B');
        $otherCourse = $this->newCourse($teacher, 'Other');
        $otherModule = $this->newModule($teacher, $otherCourse->id);

        $first = $this->newReadingLesson($teacher, $moduleA->id, 'Welcome');
        $second = $this->newReadingLesson($teacher, $moduleB->id, 'Welcome');
        $elsewhere = $this->newReadingLesson($teacher, $otherModule->id, 'Welcome');

        self::assertSame('welcome', $first->slug->value);
        self::assertSame('welcome-2', $second->slug->value);
        self::assertSame('welcome', $elsewhere->slug->value);
    }

    public function testPositionsIncrementPerModule(): void
    {
        $teacher = $this->teacher();
        $course = $this->newCourse($teacher);
        $moduleA = $this->newModule($teacher, $course->id, 'A');
        $moduleB = $this->newModule($teacher, $course->id, 'B');

        $one = $this->newReadingLesson($teacher, $moduleA->id, 'One');
        $two = $this->newReadingLesson($teacher, $moduleA->id, 'Two');
        $other = $this->newReadingLesson($teacher, $moduleB->id, 'Other');

        self::assertSame(1, $one->position);
        self::assertSame(2, $two->position);
        self::assertSame(1, $other->position);
    }

    public function testGuestMayNotAddLessons(): void
    {
        $teacher = $this->teacher();
        $module = $this->newModule($teacher, $this->newCourse($teacher)->id);

        $this->expectException(NotAuthorizedException::class);
        $this->addLesson()->execute(Principal::guest(), $module->id, 'X', ReadingLessonType::KEY, ['body' => '<p>x</p>']);
    }

    public function testUpdateLessonRevalidatesReplacementContent(): void
    {
        $teacher = $this->teacher();
        $module = $this->newModule($teacher, $this->newCourse($teacher)->id);
        $lesson = $this->newReadingLesson($teacher, $module->id);

        try {
            $this->updateLesson()->execute($teacher, $lesson->id, ['content' => ['body' => '   ']]);
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('body', $exception->errors);
        }

        $valid = $this->updateLesson()->execute($teacher, $lesson->id, [
            'title' => 'Welcome Updated',
            'content' => ['body' => '<p>New body</p>'],
        ]);

        self::assertSame('Welcome Updated', $valid->title);
        self::assertSame(['body' => '<p>New body</p>'], $this->lessons->contentOf($lesson->id));
    }

    public function testChangingTheTypeRevalidatesTheStoredContent(): void
    {
        $teacher = $this->teacher();
        $module = $this->newModule($teacher, $this->newCourse($teacher)->id);
        $lesson = $this->newReadingLesson($teacher, $module->id);

        // The stored reading body is not a valid video payload.
        $this->expectException(ValidationException::class);
        $this->updateLesson()->execute($teacher, $lesson->id, ['type' => VideoLessonType::KEY]);
    }

    public function testReorderLessonsWithinAModule(): void
    {
        $teacher = $this->teacher();
        $module = $this->newModule($teacher, $this->newCourse($teacher)->id);
        $a = $this->newReadingLesson($teacher, $module->id, 'A');
        $b = $this->newReadingLesson($teacher, $module->id, 'B');
        $c = $this->newReadingLesson($teacher, $module->id, 'C');

        (new ReorderLessons($this->lessons, $this->modules, $this->access))
            ->execute($teacher, $module->id, [$b->id, $c->id, $a->id]);

        $ordered = $this->lessons->byModule($module->id);

        self::assertSame(['B', 'C', 'A'], array_map(static fn ($l): string => $l->title, $ordered));
        self::assertSame([1, 2, 3], array_map(static fn ($l): int => $l->position, $ordered));
    }

    public function testReorderLessonsRejectsAnIncompleteList(): void
    {
        $teacher = $this->teacher();
        $module = $this->newModule($teacher, $this->newCourse($teacher)->id);
        $a = $this->newReadingLesson($teacher, $module->id, 'A');
        $this->newReadingLesson($teacher, $module->id, 'B');

        $this->expectException(ValidationException::class);
        (new ReorderLessons($this->lessons, $this->modules, $this->access))
            ->execute($teacher, $module->id, [$a->id]);
    }
}
