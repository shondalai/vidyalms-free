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

/** @var \VidyaLMS\Component\VidyaLMS\Site\View\AiActivity\HtmlView $this */
\VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::begin('aiactivity');
?>
<div id="vidyalms-credentials" data-vy-config="<?php echo $this->escape($this->configJson); ?>">
    <noscript><?php echo Text::_('COM_VIDYALMS_AI_ACTIVITY_SCRIPT_REQUIRED'); ?></noscript>
</div>

<?php \VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::end(); ?>
