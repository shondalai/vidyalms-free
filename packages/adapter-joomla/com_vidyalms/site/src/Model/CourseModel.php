<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\Model;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Shondalai\VidyaLMS\Core\Application\Commerce\CommercePrices;
use Shondalai\VidyaLMS\Core\Application\Progress\MyCourseProgress;
use Shondalai\VidyaLMS\Core\Domain\Access\Enrolment;
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Progress\LessonProgress;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use Shondalai\VidyaLMS\Core\Port\Commerce\CoursePrice;

/**
 * One public course page. Everything on it comes from a use case: the course
 * itself, the curriculum outline (the core already hides draft units from
 * anyone but an author) and, for a signed-in learner, their own enrolment and
 * progress.
 *
 * @since 1.0.0
 */
final class CourseModel extends KernelModel
{
    private bool $courseLoaded = false;

    private ?Course $course = null;

    /** @var list<\Shondalai\VidyaLMS\Core\Application\Curriculum\CurriculumSection>|null */
    private ?array $sections = null;

    private bool $enrolmentLoaded = false;

    /** @var array<string, mixed>|null */
    private ?array $enrolment = null;

    private bool $progressLoaded = false;

    private ?MyCourseProgress $progress = null;

    protected function populateState(): void
    {
        $input = Factory::getApplication()->getInput();

        $this->setState('course.slug', trim((string) $input->get('slug', '', 'string')));
    }

    public function getSlug(): string
    {
        return (string) $this->getState('course.slug', '');
    }

    /**
     * Null when the slug is unknown or the course is not visible to the caller;
     * the view turns that into a 404.
     */
    public function getCourse(): ?Course
    {
        if ($this->courseLoaded) {
            return $this->course;
        }

        $this->courseLoaded = true;
        $slug               = $this->getSlug();

        if ($slug === '') {
            return null;
        }

        try {
            $this->course = $this->kernel()->getCourseBySlug->execute($this->principal(), $slug);
        } catch (DomainException) {
            $this->course = null;
        }

        return $this->course;
    }

    /**
     * @return list<\Shondalai\VidyaLMS\Core\Application\Curriculum\CurriculumSection>
     */
    public function getSections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $course = $this->getCourse();

        if ($course === null) {
            return $this->sections = [];
        }

        try {
            return $this->sections = $this->kernel()->getCurriculum->execute($this->principal(), $course->id);
        } catch (DomainException) {
            return $this->sections = [];
        }
    }

    /**
     * The caller's own enrolment row for this course, or null for a guest and
     * for a visitor who has not enrolled.
     *
     * @return array<string, mixed>|null
     */
    public function getEnrolment(): ?array
    {
        if ($this->enrolmentLoaded) {
            return $this->enrolment;
        }

        $this->enrolmentLoaded = true;
        $course                = $this->getCourse();

        if ($course === null || $this->isGuest()) {
            return null;
        }

        try {
            return $this->enrolment = $this->kernel()->listMyEnrolments->forCourse($this->principal(), $course->id);
        } catch (DomainException) {
            return null;
        }
    }

    public function isEnrolled(): bool
    {
        return $this->getEnrolment() !== null;
    }

    public function getProgress(): ?MyCourseProgress
    {
        if ($this->progressLoaded) {
            return $this->progress;
        }

        $this->progressLoaded = true;
        $enrolment            = $this->getEnrolment();

        if ($enrolment === null) {
            return null;
        }

        try {
            return $this->progress = $this->kernel()->getMyCourseProgress->execute(
                $this->principal(),
                (int) $enrolment['id'],
            );
        } catch (DomainException) {
            return null;
        }
    }

    public function getProgressPercent(): float
    {
        $progress = $this->getProgress();

        if ($progress?->summary !== null) {
            return round($progress->summary->percent, 1);
        }

        $enrolment = $this->getEnrolment();

        return $enrolment === null ? 0.0 : round((float) ($enrolment['progress_percent'] ?? 0.0), 1);
    }

    /**
     * Lesson ids the learner has completed, keyed for lookup.
     *
     * @return array<int, bool>
     */
    public function getCompletedLessonIds(): array
    {
        $completed = [];

        foreach ($this->getProgress()->lessons ?? [] as $lesson) {
            if ($lesson instanceof LessonProgress && $lesson->isCompleted()) {
                $completed[$lesson->lessonId] = true;
            }
        }

        return $completed;
    }

    /**
     * The lesson a learner should continue with: the first one in curriculum
     * order that their progress does not mark completed.
     */
    public function getResumeLessonId(): ?int
    {
        $sections = $this->getSections();

        if ($sections === []) {
            return null;
        }

        $completed = $this->getCompletedLessonIds();
        $first     = null;

        foreach ($sections as $section) {
            foreach ($section->lessons as $lesson) {
                $first ??= $lesson->id;

                if (!isset($completed[$lesson->id])) {
                    return $lesson->id;
                }
            }
        }

        return $first;
    }

    /**
     * The display price, from the commerce source this course names.
     *
     * Resolved through the registry rather than from a hardcoded key: with a
     * constant here, a site that registered a second commerce source and
     * pinned courses to it was priced correctly by GET /courses/{slug} and
     * wrongly by this page, which is the worst of the two failures because
     * the page is the one a buyer reads.
     */
    public function getPrice(): ?CoursePrice
    {
        $course  = $this->getCourse();
        $sources = $this->kernel()->registries->commerceSources;

        if ($course === null || $course->priceMode === 'free') {
            return null;
        }

        $key = CommercePrices::resolveSourceKey($sources, $course->commerceSource);

        // A course pinned to a source whose package is gone answers no price
        // rather than a price from whichever shop happens to be installed.
        // The view renders the same "no price" it renders for a shop that has
        // not answered, which is the closed direction this registry publishes.
        return $key === null ? null : $sources->get($key)->priceFor(['id' => $course->id]);
    }

    /**
     * The buy link the commerce source answers with. Anything that is not a
     * plain http(s) or site-relative URL is dropped rather than rendered: the
     * value comes from whichever shop plugin is installed.
     */
    public function getPurchaseUrl(): ?string
    {
        $course  = $this->getCourse();
        $sources = $this->kernel()->registries->commerceSources;

        if ($course === null || $course->priceMode === 'free') {
            return null;
        }

        $key = CommercePrices::resolveSourceKey($sources, $course->commerceSource);

        if ($key === null) {
            return null;
        }

        $url = $sources->get($key)->purchaseUrlFor(['id' => $course->id]);

        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        if (preg_match('#^https?://#i', $url) === 1 || str_starts_with($url, '/')) {
            return $url;
        }

        return preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1 ? null : $url;
    }

    /**
     * Whether the enrol button is worth showing.
     *
     * The question goes to the core, which loads the same course row and asks
     * the same registered rule that SelfEnrol::execute() will, so the button
     * and the POST cannot disagree. Asking the rule from here with a
     * hand-built row would look equivalent and quietly drift the moment a
     * site policy or a Pro rule reads a column this model did not copy.
     */
    public function canOfferSelfEnrolment(): bool
    {
        $course = $this->getCourse();

        if ($course === null) {
            return false;
        }

        return $this->kernel()->selfEnrol->isAvailableFor($this->principal(), $course->id);
    }

    /**
     * Self-enrolment, straight through to the use case. Every rule (published,
     * free, open, already enrolled, and the site switch) is decided there and
     * signalled as a domain exception the controller turns into a message.
     */
    public function enrol(int $courseId): Enrolment
    {
        return $this->kernel()->selfEnrol->execute($this->principal(), $courseId);
    }

    /**
     * The core exposes no read use case for course instructors yet, so the
     * page renders the block only once one exists.
     *
     * @return list<array{id: int, name: string, role: string}>
     */
    public function getInstructors(): array
    {
        return [];
    }

    /**
     * As above: prerequisites are written by SetPrerequisites but not yet
     * readable through a use case.
     *
     * @return list<array{slug: string, title: string}>
     */
    public function getPrerequisites(): array
    {
        return [];
    }
}
