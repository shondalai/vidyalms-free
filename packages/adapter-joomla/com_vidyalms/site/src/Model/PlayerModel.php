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
use Shondalai\VidyaLMS\Core\Domain\Curriculum\Course;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;

/**
 * The player page: which course the mount belongs to, which lesson the deep
 * link names, and whether a guest may see the page at all. The React app asks
 * the REST surface for everything else, so this model stays deliberately
 * small; it exists to render one div with one config attribute.
 *
 * @since 1.0.0
 */
final class PlayerModel extends KernelModel
{
    private bool $courseLoaded = false;

    private ?Course $course = null;

    private ?bool $freePreview = null;

    protected function populateState(): void
    {
        $input = Factory::getApplication()->getInput();

        $this->setState('course.slug', trim((string) $input->get('slug', '', 'string')));
        $this->setState('player.lesson', (int) $input->get('vy-lesson', 0, 'int'));
    }

    public function getSlug(): string
    {
        return (string) $this->getState('course.slug', '');
    }

    /**
     * The deep-linked lesson, or null when the player should resume on its
     * own. The id is not validated here: the app treats an unknown lesson id
     * as "no deep link", which is the honest answer either way.
     */
    public function getLessonId(): ?int
    {
        $lessonId = (int) $this->getState('player.lesson', 0);

        return $lessonId > 0 ? $lessonId : null;
    }

    /**
     * Null when the slug is unknown or the course is not visible to the
     * caller; the view turns that into a 404.
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
     * Whether the course offers any free-preview lesson to the caller. This is
     * the one fact the login guard needs: a guest may open the player only
     * when there is something the core will actually serve them.
     */
    public function hasFreePreviewContent(): bool
    {
        if ($this->freePreview !== null) {
            return $this->freePreview;
        }

        $course = $this->getCourse();

        if ($course === null) {
            return $this->freePreview = false;
        }

        try {
            $sections = $this->kernel()->getCurriculum->execute($this->principal(), $course->id);
        } catch (DomainException) {
            return $this->freePreview = false;
        }

        foreach ($sections as $section) {
            foreach ($section->lessons as $lesson) {
                if ($lesson->freePreview) {
                    return $this->freePreview = true;
                }
            }
        }

        return $this->freePreview = false;
    }
}
