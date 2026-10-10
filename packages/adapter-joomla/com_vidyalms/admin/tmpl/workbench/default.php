<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \VidyaLMS\Component\VidyaLMS\Administrator\View\Workbench\HtmlView $this */
?>
<div class="vidyalms vidyalms-app-screen">
    <div id="<?php echo $this->escape($this->mountId); ?>" data-vy-config="<?php echo $this->escape($this->configJson); ?>">
        <noscript>
            <p class="vidyalms-notice"><?php echo Text::_('COM_VIDYALMS_APP_NOSCRIPT'); ?></p>
        </noscript>
    </div>
</div>
