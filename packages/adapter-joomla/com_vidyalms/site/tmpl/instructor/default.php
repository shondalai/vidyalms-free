<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \VidyaLMS\Component\VidyaLMS\Site\View\Instructor\HtmlView $this */

$heading = (string) $this->params->get('page_heading', $this->params->get('page_title', ''));

if ($heading === '') {
    $heading = Text::_('COM_VIDYALMS_INSTRUCTOR_TITLE');
}
?>
<div class="vidyalms vidyalms-instructor-page">
    <?php if ((int) $this->params->get('show_page_heading', 0) === 1) : ?>
        <h1 class="vidyalms-page-title"><?php echo $this->escape($heading); ?></h1>
    <?php endif; ?>

    <div id="<?php echo $this->escape($this->mountId); ?>" data-vy-config="<?php echo $this->escape($this->configJson); ?>">
        <noscript>
            <p class="vidyalms-notice">
                <?php echo Text::_('COM_VIDYALMS_INSTRUCTOR_NOSCRIPT'); ?>
                <a class="vidyalms-link" href="<?php echo $this->myLearningUrl; ?>"><?php echo Text::_('COM_VIDYALMS_MYLEARNING_TITLE'); ?></a>
            </p>
        </noscript>
    </div>
</div>
