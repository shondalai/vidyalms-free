<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\View\Screen;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\AdminScreens;
use VidyaLMS\Component\VidyaLMS\Administrator\View\AppView;

/**
 * Generic host view for administrator screens supplied by another package.
 *
 * @since 1.4.0
 */
final class HtmlView extends AppView
{
    /**
     * Resolve the contributed screen before AppView builds its mount config.
     *
     * @param string|null $tpl
     */
    public function display($tpl = null): void
    {
        $key        = Factory::getApplication()->getInput()->getCmd('screen', '');
        $definition = AdminScreens::registry()->get($key);

        if ($definition === null) {
            throw new \RuntimeException(Text::_('COM_VIDYALMS_ERROR_ADMIN_SCREEN_NOT_FOUND'), 404);
        }

        $this->screen    = $definition->key;
        $this->titleKey  = $definition->titleKey;
        $this->titleIcon = $definition->icon;

        parent::display($tpl);
    }
}
