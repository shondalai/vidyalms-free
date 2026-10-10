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
use Joomla\CMS\Router\Route;
use VidyaLMS\Component\VidyaLMS\Site\Service\LearnerLayout;

/** @var \VidyaLMS\Component\VidyaLMS\Site\View\Player\HtmlView $this */

$courseUrl = Route::_('index.php?option=com_vidyalms&view=course&slug=' . urlencode($this->course->slug->value));
LearnerLayout::begin('mylearning');
?>
<div class="vidyalms vidyalms-player-page">
    <div id="vidyalms-player" data-vy-config="<?php echo $this->escape($this->configJson); ?>">
        <noscript>
            <p class="vidyalms-notice">
                <?php echo Text::_('COM_VIDYALMS_PLAYER_NOSCRIPT'); ?>
                <a class="vidyalms-link" href="<?php echo $courseUrl; ?>"><?php echo Text::_('COM_VIDYALMS_PLAYER_BACK_TO_COURSE'); ?></a>
            </p>
        </noscript>
    </div>
</div>
<?php LearnerLayout::end(); ?>
