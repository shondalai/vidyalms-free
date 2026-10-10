<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Filter\InputFilter;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \VidyaLMS\Component\VidyaLMS\Site\View\Course\HtmlView $this */

$language = Factory::getApplication()->getLanguage();
$course   = $this->course;
$level    = (string) ($course->level ?? '');
$levelKey = 'COM_VIDYALMS_LEVEL_' . strtoupper($level);

$duration = static function (?int $seconds): string {
    if ($seconds === null || $seconds <= 0) {
        return '';
    }

    $minutes = max(1, (int) round($seconds / 60));

    if ($minutes < 60) {
        return Text::plural('COM_VIDYALMS_DURATION_MINUTES_N', $minutes);
    }

    return Text::sprintf('COM_VIDYALMS_DURATION_HOURS', intdiv($minutes, 60), $minutes % 60);
};
\VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::begin('catalogue');
?>
<div class="vidyalms vidyalms-course">
    <article class="vidyalms-course-main">
        <header class="vidyalms-course-header">
            <h1 class="vidyalms-page-title"><?php echo $this->escape($course->title); ?></h1>

            <p class="vidyalms-card-meta">
                <?php if ($level !== '') : ?>
                    <span class="vidyalms-badge">
                        <span class="vidyalms-badge-label"><?php echo Text::_('COM_VIDYALMS_COURSE_LEVEL'); ?></span>
                        <?php echo $this->escape($language->hasKey($levelKey) ? Text::_($levelKey) : $level); ?>
                    </span>
                <?php endif; ?>

                <?php if ($course->priceMode === 'free') : ?>
                    <span class="vidyalms-badge vidyalms-badge-free"><?php echo Text::_('COM_VIDYALMS_COURSE_FREE'); ?></span>
                <?php elseif ($this->price !== null) : ?>
                    <span class="vidyalms-badge vidyalms-badge-price">
                        <span class="vidyalms-badge-label"><?php echo Text::_('COM_VIDYALMS_COURSE_PRICE'); ?></span>
                        <?php echo $this->escape($this->price->formatted ?? ($this->price->currency . ' ' . number_format($this->price->amountMinor / 100, 2))); ?>
                    </span>
                <?php endif; ?>
            </p>

            <?php if (!empty($course->summary)) : ?>
                <p class="vidyalms-course-summary"><?php echo $this->escape((string) $course->summary); ?></p>
            <?php endif; ?>
        </header>

        <?php if (!empty($course->image)) : ?>
            <?php $image = HTMLHelper::cleanImageURL((string) $course->image); ?>
            <div class="vidyalms-course-media">
                <?php echo HTMLHelper::_('image', $image->url, '', ['class' => 'vidyalms-course-image', 'loading' => 'lazy']); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($course->description)) : ?>
            <section class="vidyalms-course-description" aria-labelledby="vidyalms-about-heading">
                <h2 id="vidyalms-about-heading"><?php echo Text::_('COM_VIDYALMS_COURSE_ABOUT'); ?></h2>
                <?php echo \Shondalai\VidyaLMS\Core\Domain\Shared\HtmlPolicy::safe((string) $course->description); ?>
            </section>
        <?php endif; ?>

        <?php if ($this->instructors !== []) : ?>
            <section class="vidyalms-course-instructors" aria-labelledby="vidyalms-instructors-heading">
                <h2 id="vidyalms-instructors-heading"><?php echo Text::_('COM_VIDYALMS_COURSE_INSTRUCTORS'); ?></h2>
                <ul class="vidyalms-list">
                    <?php foreach ($this->instructors as $instructor) : ?>
                        <li><?php echo $this->escape((string) $instructor['name']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ($this->prerequisites !== []) : ?>
            <section class="vidyalms-course-prerequisites" aria-labelledby="vidyalms-prerequisites-heading">
                <h2 id="vidyalms-prerequisites-heading"><?php echo Text::_('COM_VIDYALMS_COURSE_PREREQUISITES'); ?></h2>
                <ul class="vidyalms-list">
                    <?php foreach ($this->prerequisites as $prerequisite) : ?>
                        <li>
                            <a href="<?php echo Route::_('index.php?option=com_vidyalms&view=course&slug=' . urlencode((string) $prerequisite['slug'])); ?>">
                                <?php echo $this->escape((string) $prerequisite['title']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <section class="vidyalms-curriculum" id="vidyalms-curriculum" aria-labelledby="vidyalms-curriculum-heading">
            <h2 id="vidyalms-curriculum-heading"><?php echo Text::_('COM_VIDYALMS_COURSE_CURRICULUM'); ?></h2>

            <?php if ($this->sections === []) : ?>
                <p class="vidyalms-empty"><?php echo Text::_('COM_VIDYALMS_COURSE_CURRICULUM_EMPTY'); ?></p>
            <?php else : ?>
                <?php foreach ($this->sections as $index => $section) : ?>
                    <section class="vidyalms-module" aria-labelledby="vidyalms-module-<?php echo (int) $section->module->id; ?>">
                        <h3 class="vidyalms-module-title" id="vidyalms-module-<?php echo (int) $section->module->id; ?>">
                            <?php echo Text::sprintf('COM_VIDYALMS_COURSE_MODULE_NUMBER', $index + 1); ?>
                            <span class="vidyalms-module-name"><?php echo $this->escape($section->module->title); ?></span>
                        </h3>

                        <?php if (!empty($section->module->summary)) : ?>
                            <p class="vidyalms-module-summary"><?php echo $this->escape((string) $section->module->summary); ?></p>
                        <?php endif; ?>

                        <ol class="vidyalms-lessons">
                            <?php foreach ($section->lessons as $lesson) : ?>
                                <?php
                                $typeKey    = 'COM_VIDYALMS_LESSON_TYPE_' . strtoupper($lesson->type);
                                $length     = $duration($lesson->durationSeconds);
                                $completed  = isset($this->completedLessonIds[$lesson->id]);
                                $previewUrl = $this->previewLessonUrls[$lesson->id] ?? null;
                                ?>
                                <li class="vidyalms-lesson<?php echo $completed ? ' vidyalms-lesson-complete' : ''; ?>" id="lesson-<?php echo (int) $lesson->id; ?>">
                                    <?php if ($previewUrl !== null) : ?>
                                        <a class="vidyalms-lesson-title vidyalms-lesson-preview" href="<?php echo $previewUrl; ?>"><?php echo $this->escape($lesson->title); ?></a>
                                    <?php else : ?>
                                        <span class="vidyalms-lesson-title"><?php echo $this->escape($lesson->title); ?></span>
                                    <?php endif; ?>
                                    <span class="vidyalms-lesson-meta">
                                        <span class="vidyalms-lesson-type"><?php echo $this->escape($language->hasKey($typeKey) ? Text::_($typeKey) : $lesson->type); ?></span>
                                        <?php if ($length !== '') : ?>
                                            <span class="vidyalms-lesson-duration"><?php echo $this->escape($length); ?></span>
                                        <?php endif; ?>
                                        <?php if ($lesson->freePreview) : ?>
                                            <span class="vidyalms-badge vidyalms-badge-preview"><?php echo Text::_('COM_VIDYALMS_COURSE_FREE_PREVIEW'); ?></span>
                                        <?php endif; ?>
                                        <?php if ($completed) : ?>
                                            <span class="vidyalms-lesson-state"><?php echo Text::_('COM_VIDYALMS_COURSE_LESSON_COMPLETED'); ?></span>
                                        <?php endif; ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </section>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </article>

    <aside class="vidyalms-course-aside" aria-labelledby="vidyalms-enrol-heading">
        <h2 id="vidyalms-enrol-heading" class="vidyalms-aside-title"><?php echo Text::_('COM_VIDYALMS_COURSE_ENROLMENT'); ?></h2>

        <?php if ($this->enrolment !== null) : ?>
            <p class="vidyalms-enrolled"><?php echo Text::_('COM_VIDYALMS_COURSE_YOU_ARE_ENROLLED'); ?></p>

            <div class="vidyalms-progress">
                <label class="vidyalms-label" for="vidyalms-progress-bar"><?php echo Text::_('COM_VIDYALMS_COURSE_YOUR_PROGRESS'); ?></label>
                <progress class="vidyalms-progress-bar" id="vidyalms-progress-bar" max="100" value="<?php echo $this->escape((string) $this->percent); ?>">
                    <?php echo Text::sprintf('COM_VIDYALMS_PROGRESS_PERCENT', $this->percent); ?>
                </progress>
                <p class="vidyalms-progress-value"><?php echo Text::sprintf('COM_VIDYALMS_PROGRESS_PERCENT', $this->percent); ?></p>
            </div>

            <?php
            // The interactive player owns the learning surface; the resume
            // lesson rides along as the deep link when there is one.
            $playerQuery = 'index.php?option=com_vidyalms&view=player&slug=' . urlencode($course->slug->value)
                . ($this->resumeLessonId !== null ? '&vy-lesson=' . (int) $this->resumeLessonId : '');
            ?>
            <p>
                <a class="vidyalms-button" href="<?php echo Route::_($playerQuery); ?>">
                    <?php echo Text::_($this->resumeLessonId !== null ? 'COM_VIDYALMS_COURSE_RESUME' : 'COM_VIDYALMS_COURSE_START_LEARNING'); ?>
                </a>
            </p>

            <p>
                <a class="vidyalms-link" href="<?php echo Route::_('index.php?option=com_vidyalms&view=mylearning'); ?>">
                    <?php echo Text::_('COM_VIDYALMS_MYLEARNING_TITLE'); ?>
                </a>
            </p>
        <?php elseif ($this->isGuest) : ?>
            <p class="vidyalms-notice"><?php echo Text::_('COM_VIDYALMS_COURSE_LOGIN_TO_ENROL'); ?></p>
            <p>
                <a class="vidyalms-button" href="<?php echo $this->loginUrl; ?>" data-no-swup><?php echo Text::_('COM_VIDYALMS_LOGIN'); ?></a>
            </p>
        <?php elseif ($this->canEnrol) : ?>
            <form class="vidyalms-enrol-form" action="<?php echo $this->formAction; ?>" method="post">
                <input type="hidden" name="task" value="enrol">
                <input type="hidden" name="id" value="<?php echo (int) $course->id; ?>">
                <input type="hidden" name="slug" value="<?php echo $this->escape($course->slug->value); ?>">
                <?php if ($this->itemId > 0) : ?>
                    <input type="hidden" name="Itemid" value="<?php echo $this->itemId; ?>">
                <?php endif; ?>
                <?php echo HTMLHelper::_('form.token'); ?>
                <button class="vidyalms-button" type="submit"><?php echo Text::_('COM_VIDYALMS_COURSE_ENROL_NOW'); ?></button>
            </form>
        <?php elseif ($this->purchaseUrl !== null) : ?>
            <p>
                <a class="vidyalms-button" href="<?php echo $this->escape($this->purchaseUrl); ?>">
                    <?php echo Text::_('COM_VIDYALMS_COURSE_BUY_NOW'); ?>
                </a>
            </p>
        <?php else : ?>
            <p class="vidyalms-notice"><?php echo Text::_('COM_VIDYALMS_COURSE_ENROLMENT_CLOSED'); ?></p>
        <?php endif; ?>
    </aside>
</div>

<?php \VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::end(); ?>
