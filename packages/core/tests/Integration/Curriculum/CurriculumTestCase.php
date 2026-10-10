<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Curriculum;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddLesson;
use Shondalai\VidyaLMS\Core\Application\Curriculum\AddModule;
use Shondalai\VidyaLMS\Core\Application\Curriculum\CreateCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\PublishCourse;
use Shondalai\VidyaLMS\Core\Application\Curriculum\UpdateCourse;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Application\Shared\CourseAccess;
use Shondalai\VidyaLMS\Core\Application\Shared\EventBus;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Lesson;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Module;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Access\DatabaseEnrolmentRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseCourseRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseLessonRepository;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseModuleRepository;
use Shondalai\VidyaLMS\Core\Provider\LessonType\ReadingLessonType;
use Shondalai\VidyaLMS\Core\Provider\LessonType\ResourceLessonType;
use Shondalai\VidyaLMS\Core\Provider\LessonType\VideoLessonType;
use Shondalai\VidyaLMS\Core\Provider\EnrolmentRule\ManualEnrolmentRule;
use Shondalai\VidyaLMS\Core\Provider\EnrolmentRule\OpenEnrolmentRule;
use Shondalai\VidyaLMS\Core\Provider\LatePolicy\NoLatePolicy;
use Shondalai\VidyaLMS\Core\Registry\EnrolmentRule\EnrolmentRuleRegistry;
use Shondalai\VidyaLMS\Core\Registry\LatePolicy\LatePolicyRegistry;
use Shondalai\VidyaLMS\Core\Registry\LessonType\LessonTypeRegistry;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

abstract class CurriculumTestCase extends TestCase
{
    protected TestPorts $env;
    protected DatabaseCourseRepository $courses;
    protected DatabaseModuleRepository $modules;
    protected DatabaseLessonRepository $lessons;
    protected AccessGuard $guard;
    protected CourseAccess $access;
    protected LessonTypeRegistry $lessonTypes;
    protected EventBus $events;
    protected EnrolmentRuleRegistry $enrolmentRules;
    protected LatePolicyRegistry $latePolicies;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();

        $this->lessonTypes = new LessonTypeRegistry();
        $this->lessonTypes->register(new VideoLessonType($this->env->translator));
        $this->lessonTypes->register(new ReadingLessonType($this->env->translator));
        $this->lessonTypes->register(new ResourceLessonType($this->env->translator));

        $this->courses = new DatabaseCourseRepository($this->env->database, $this->env->clock);
        $this->modules = new DatabaseModuleRepository($this->env->database, $this->env->clock);
        $this->lessons = new DatabaseLessonRepository($this->env->database, $this->env->clock, $this->lessonTypes);
        $this->guard = new AccessGuard($this->env->authorization);
        $this->access = new CourseAccess(
            $this->guard,
            $this->courses,
            new DatabaseEnrolmentRepository($this->env->database, $this->env->clock),
        );
        $this->events = new EventBus($this->env->eventBridge, $this->env->ports->logger);
        $this->enrolmentRules = new EnrolmentRuleRegistry();
        $this->enrolmentRules->register(new OpenEnrolmentRule($this->env->translator));
        $this->enrolmentRules->register(new ManualEnrolmentRule($this->env->translator));
        $this->latePolicies = new LatePolicyRegistry();
        $this->latePolicies->register(new NoLatePolicy());
    }

    protected function teacher(int $userId = 10): Principal
    {
        $this->env->authorization->grant($userId, Capability::TEACH);

        return Principal::user($userId);
    }

    protected function manager(int $userId = 20): Principal
    {
        $this->env->authorization->grant($userId, Capability::MANAGE);

        return Principal::user($userId);
    }

    protected function learner(int $userId = 30): Principal
    {
        return Principal::user($userId);
    }

    protected function createCourseUseCase(): CreateCourse
    {
        return new CreateCourse(
            $this->courses,
            $this->guard,
            $this->env->database,
            $this->events,
            $this->env->clock,
            $this->env->courseCategories,
        );
    }

    protected function updateCourseUseCase(): UpdateCourse
    {
        return new UpdateCourse(
            $this->courses,
            $this->access,
            $this->events,
            $this->env->clock,
            $this->env->courseCategories,
            $this->enrolmentRules,
            $this->latePolicies,
        );
    }

    protected function newCourse(Principal $teacher, string $title = 'PHP Basics'): Course
    {
        return $this->createCourseUseCase()->execute($teacher, $title);
    }

    protected function newModule(Principal $teacher, int $courseId, string $title = 'Getting Started'): Module
    {
        return (new AddModule($this->modules, $this->access))
            ->execute($teacher, $courseId, $title);
    }

    protected function newReadingLesson(
        Principal $teacher,
        int $moduleId,
        string $title = 'Welcome',
        string $state = Lesson::STATE_PUBLISHED,
    ): Lesson {
        return (new AddLesson($this->lessons, $this->modules, $this->access, $this->lessonTypes))
            ->execute(
                $teacher,
                $moduleId,
                $title,
                ReadingLessonType::KEY,
                ['body' => '<p>Hello</p>'],
                state: $state,
            );
    }

    protected function publishedCourse(Principal $teacher, string $title = 'PHP Basics'): Course
    {
        $course = $this->newCourse($teacher, $title);
        $module = $this->newModule($teacher, $course->id);
        $this->newReadingLesson($teacher, $module->id);

        return (new PublishCourse($this->courses, $this->lessons, $this->access, $this->env->clock, $this->events))
            ->execute($teacher, $course->id);
    }
}
