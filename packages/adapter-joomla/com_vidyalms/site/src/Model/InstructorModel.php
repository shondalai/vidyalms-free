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

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;

/**
 * The frontend instructor page's one question: what is the slug of the course
 * this page was asked for.
 *
 * It answers through GetManagedCourse, which applies the per-course rule, and
 * it answers an empty string for a caller who may not read that course. It
 * never decides whether the page renders: the app asks the same route and
 * renders whatever the core refuses it with, so the refusal reads the same on
 * this page as it does in the administrator and on WordPress. Deciding it here
 * would put a second copy of the rule in the adapter.
 *
 * @since 1.0.0
 */
final class InstructorModel extends KernelModel
{
    /**
     * The slug of a course the caller may manage, or an empty string when the
     * id is unknown, hidden or refused.
     */
    public function getCourseSlug(int $courseId): string
    {
        if ($courseId <= 0) {
            return '';
        }

        try {
            return $this->kernel()->getManagedCourse
                ->execute($this->principal(), $courseId)
                ->managed->course->slug->value;
        } catch (DomainException) {
            return '';
        }
    }
}
