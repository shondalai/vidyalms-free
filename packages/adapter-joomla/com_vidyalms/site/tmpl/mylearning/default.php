<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

/** @var \VidyaLMS\Component\VidyaLMS\Site\View\MyLearning\HtmlView $this */

$heading = (string) $this->params->get('page_heading', $this->params->get('page_title', ''));

if ($heading === '') {
    $heading = Text::_('COM_VIDYALMS_MYLEARNING_TITLE');
}
\VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::begin('mylearning');
?>
<div class="vidyalms vidyalms-mylearning">
    <p class="vy-page-kicker"><?php echo Text::_('COM_VIDYALMS_LEARNING_EYEBROW'); ?></p>
    <?php if ((int) $this->params->get('show_page_heading', 1) === 1) : ?>
        <h1 class="vidyalms-page-title"><?php echo $this->escape($heading); ?></h1>
    <?php endif; ?>

    <p class="vy-page-description"><?php echo Text::_('COM_VIDYALMS_LEARNING_INTRO'); ?></p>
    <form method="get" data-vy-page-form class="vy-learning-filter" action="<?php echo $this->escape(Route::_('index.php?option=com_vidyalms&view=mylearning', false)); ?>">
        <input type="hidden" name="option" value="com_vidyalms"><input type="hidden" name="view" value="mylearning">
        <?php $itemId = (int) \Joomla\CMS\Factory::getApplication()->getInput()->getInt('Itemid'); ?>
        <?php if ($itemId > 0) : ?><input type="hidden" name="Itemid" value="<?php echo $itemId; ?>"><?php endif; ?>
        <label for="vy-learning-source"><?php echo Text::_('COM_VIDYALMS_LEARNING_SOURCE'); ?></label>
        <select id="vy-learning-source" name="organisation_id">
            <option value=""><?php echo Text::_('COM_VIDYALMS_LEARNING_ALL'); ?></option>
            <option value="0" <?php echo $this->organisationFilter === 0 ? 'selected' : ''; ?>><?php echo Text::_('COM_VIDYALMS_LEARNING_PERSONAL'); ?></option>
            <?php foreach ($this->organisationOptions as $organisation) : ?>
                <option value="<?php echo $organisation['id']; ?>" <?php echo $this->organisationFilter === $organisation['id'] ? 'selected' : ''; ?>><?php echo $this->escape($organisation['name']); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="vidyalms-button"><?php echo Text::_('COM_VIDYALMS_LEARNING_FILTER'); ?></button>
    </form>
    <?php foreach ($this->enrolments as $resume) : ?>
        <?php if (($resume['status'] ?? '') !== 'active' || ($resume['course_slug'] ?? '') === '' || (float) ($resume['progress_percent'] ?? 0) >= 100) { continue; } ?>
        <section class="vy-resume-course">
            <div><p class="vy-page-kicker"><?php echo Text::_('COM_VIDYALMS_PICK_UP_LEARNING'); ?></p><h2><?php echo $this->escape((string) $resume['course_title']); ?></h2><p><?php echo Text::sprintf('COM_VIDYALMS_PROGRESS_PERCENT', round((float) ($resume['progress_percent'] ?? 0), 1)); ?></p></div>
            <a class="vidyalms-button" href="<?php echo $this->escape(Route::_('index.php?option=com_vidyalms&view=player&slug=' . urlencode((string) $resume['course_slug']), false)); ?>"><?php echo Text::_('COM_VIDYALMS_MYLEARNING_CONTINUE_LEARNING'); ?></a>
        </section>
        <?php break; ?>
    <?php endforeach; ?>
    <nav class="vidyalms-learner-navigation" aria-label="<?php echo $this->escape(Text::_('COM_VIDYALMS_LEARNER_NAVIGATION')); ?>">
        <a class="vidyalms-button" href="<?php echo $this->escape(Route::_('index.php?option=com_vidyalms&view=achievements', false)); ?>"><?php echo Text::_('COM_VIDYALMS_ACHIEVEMENTS_TITLE'); ?></a>
        <a class="vidyalms-button" href="<?php echo $this->escape(Route::_('index.php?option=com_vidyalms&view=organisations', false)); ?>"><?php echo Text::_('COM_VIDYALMS_ORGANISATIONS_TITLE'); ?></a>
        <a class="vidyalms-button" href="<?php echo $this->escape(Route::_('index.php?option=com_vidyalms&view=preferences', false)); ?>"><?php echo Text::_('COM_VIDYALMS_LEARNER_PREFERENCES'); ?></a>
    </nav>

    <section class="vidyalms-enrolments" aria-labelledby="vidyalms-enrolments-heading">
        <h2 id="vidyalms-enrolments-heading"><?php echo Text::_('COM_VIDYALMS_MYLEARNING_COURSES'); ?></h2>

        <?php if (!$this->enrolmentsAvailable) : ?>
            <p class="vidyalms-notice vidyalms-notice-error" role="alert"><?php echo Text::_('COM_VIDYALMS_MYLEARNING_UNAVAILABLE'); ?></p>
        <?php elseif ($this->enrolments === []) : ?>
            <p class="vidyalms-empty"><?php echo Text::_($this->organisationFilter !== null ? 'COM_VIDYALMS_LEARNING_NO_FILTER_COURSES' : 'COM_VIDYALMS_MYLEARNING_NO_COURSES'); ?></p>
            <p>
                <a class="vidyalms-button" href="<?php echo $this->catalogueUrl; ?>">
                    <?php echo Text::_('COM_VIDYALMS_MYLEARNING_BROWSE_CATALOGUE'); ?>
                </a>
            </p>
        <?php else : ?>
            <p class="vidyalms-result-count" role="status"><?php echo Text::sprintf(
                'COM_VIDYALMS_MYLEARNING_RANGE',
                ($this->enrolmentPage - 1) * 20 + 1,
                ($this->enrolmentPage - 1) * 20 + \count($this->enrolments),
                $this->enrolmentTotal,
            ); ?></p>
            <ul class="vidyalms-enrolment-list">
                <?php foreach ($this->enrolments as $enrolment) : ?>
                    <?php
                    $slug     = (string) ($enrolment['course_slug'] ?? '');
                    $title    = (string) ($enrolment['course_title'] ?? '');
                    $status   = (string) ($enrolment['status'] ?? '');
                    $percent  = round((float) ($enrolment['progress_percent'] ?? 0.0), 1);
                    $statusId = 'vidyalms-status-' . (int) ($enrolment['id'] ?? 0);
                    $barId    = 'vidyalms-bar-' . (int) ($enrolment['id'] ?? 0);
                    $url      = $slug === ''
                        ? ''
                        : Route::_('index.php?option=com_vidyalms&view=course&slug=' . urlencode($slug));

                    // Continue opens the interactive player for an enrolment
                    // the learner can still work in; a suspended or cancelled
                    // one goes to the course page, which explains itself.
                    $playerUrl = $url !== '' && \in_array($status, ['active', 'completed'], true)
                        ? Route::_('index.php?option=com_vidyalms&view=player&slug=' . urlencode($slug))
                        : '';
                    ?>
                    <li class="vidyalms-enrolment">
                        <h3 class="vidyalms-enrolment-title">
                            <?php if ($url !== '') : ?>
                                <a href="<?php echo $url; ?>"><?php echo $this->escape($title); ?></a>
                            <?php else : ?>
                                <?php echo $this->escape($title); ?>
                            <?php endif; ?>
                        </h3>

                        <p class="vy-learning-sources"><span><?php echo Text::_('COM_VIDYALMS_LEARNING_SOURCES'); ?></span>
                            <?php foreach ($enrolment['organisations'] ?? [] as $organisation) : ?>
                                <span class="vidyalms-badge"><?php echo $this->escape($organisation['name']); ?></span>
                            <?php endforeach; ?>
                            <?php if (!empty($enrolment['personal_enrolment'])) : ?><span class="vidyalms-badge"><?php echo Text::_('COM_VIDYALMS_LEARNING_PERSONAL'); ?></span><?php endif; ?>
                        </p>

                        <p class="vidyalms-enrolment-status" id="<?php echo $statusId; ?>">
                            <span class="vidyalms-badge-label"><?php echo Text::_('COM_VIDYALMS_ENROLMENT_STATUS'); ?></span>
                            <?php echo Text::_('COM_VIDYALMS_ENROLMENT_STATUS_' . strtoupper($status)); ?>
                        </p>

                        <label class="vidyalms-label" for="<?php echo $barId; ?>"><?php echo Text::_('COM_VIDYALMS_COURSE_YOUR_PROGRESS'); ?></label>
                        <progress class="vidyalms-progress-bar" id="<?php echo $barId; ?>" max="100" value="<?php echo $this->escape((string) $percent); ?>">
                            <?php echo Text::sprintf('COM_VIDYALMS_PROGRESS_PERCENT', $percent); ?>
                        </progress>
                        <p class="vidyalms-progress-value"><?php echo Text::sprintf('COM_VIDYALMS_PROGRESS_PERCENT', $percent); ?></p>

                        <?php if ($playerUrl !== '') : ?>
                            <p>
                                <a class="vidyalms-button" href="<?php echo $playerUrl; ?>"><?php echo Text::_('COM_VIDYALMS_MYLEARNING_CONTINUE_LEARNING'); ?></a>
                                <a class="vidyalms-link" href="<?php echo $url; ?>"><?php echo Text::_('COM_VIDYALMS_MYLEARNING_CONTINUE'); ?></a>
                            </p>
                        <?php elseif ($url !== '') : ?>
                            <p>
                                <a class="vidyalms-link" href="<?php echo $url; ?>"><?php echo Text::_('COM_VIDYALMS_MYLEARNING_CONTINUE'); ?></a>
                            </p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ($this->pagination->pagesTotal > 1) : ?>
                <nav class="vidyalms-pagination" aria-label="<?php echo $this->escape(Text::_('COM_VIDYALMS_MYLEARNING_PAGINATION')); ?>">
                    <?php echo $this->pagination->getPagesLinks(); ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <section class="vidyalms-certificates" aria-labelledby="vidyalms-certificates-heading">
        <h2 id="vidyalms-certificates-heading"><?php echo Text::_('COM_VIDYALMS_MYLEARNING_CERTIFICATES'); ?></h2>

        <?php if ($this->certificates === []) : ?>
            <p class="vidyalms-empty"><?php echo Text::_('COM_VIDYALMS_MYLEARNING_NO_CERTIFICATES'); ?></p>
        <?php else : ?>
            <ul class="vidyalms-certificate-list">
                <?php foreach ($this->certificates as $certificate) : ?>
                    <?php
                    $course = $this->courseIndex[$certificate->courseId] ?? ['title' => '', 'slug' => ''];
                    $title  = (string) $course['title'];
                    $verify = Route::_('index.php?option=com_vidyalms&view=verify&code=' . urlencode($certificate->code));
                    ?>
                    <li class="vidyalms-certificate">
                        <h3 class="vidyalms-certificate-title">
                            <?php echo $this->escape($title !== '' ? $title : Text::_('COM_VIDYALMS_CERTIFICATE')); ?>
                        </h3>
                        <p class="vidyalms-certificate-meta">
                            <span class="vidyalms-badge-label"><?php echo Text::_('COM_VIDYALMS_CERTIFICATE_CODE'); ?></span>
                            <code class="vidyalms-code"><?php echo $this->escape($certificate->code); ?></code>
                        </p>
                        <p class="vidyalms-certificate-meta">
                            <span class="vidyalms-badge-label"><?php echo Text::_('COM_VIDYALMS_CERTIFICATE_ISSUED'); ?></span>
                            <?php echo HTMLHelper::_('date', $certificate->issuedAt->format('Y-m-d H:i:s'), Text::_('DATE_FORMAT_LC3')); ?>
                        </p>
                        <?php if ($certificate->isRevoked()) : ?>
                            <p class="vidyalms-notice"><?php echo Text::_('COM_VIDYALMS_CERTIFICATE_REVOKED'); ?></p>
                        <?php endif; ?>
                        <p>
                            <a class="vidyalms-link" href="<?php echo $verify; ?>"><?php echo Text::_('COM_VIDYALMS_CERTIFICATE_VERIFY_LINK'); ?></a>
                        </p>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </section>
</div>

<?php \VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::end(); ?>
