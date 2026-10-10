<?php

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \VidyaLMS\Component\VidyaLMS\Site\View\Organisations\HtmlView $this */
\VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::begin('organisations');
?>
<div id="vidyalms-credentials" data-vy-config="<?php echo $this->escape($this->configJson); ?>">
    <noscript><?php echo Text::_('COM_VIDYALMS_ORGANISATIONS_SCRIPT_REQUIRED'); ?></noscript>
</div>

<?php \VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout::end(); ?>
