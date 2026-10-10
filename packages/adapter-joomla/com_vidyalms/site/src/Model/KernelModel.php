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
use Joomla\CMS\MVC\Model\BaseModel;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\KernelFactory;

/**
 * Base for the site read models. They hold no queries of their own: every
 * answer comes from a core use case, and this class is the single place that
 * reaches the shared kernel and translates the Joomla identity into a core
 * principal.
 *
 * @since 1.0.0
 */
abstract class KernelModel extends BaseModel
{
    protected function kernel(): CoreKernel
    {
        return KernelFactory::kernel();
    }

    protected function principal(): Principal
    {
        $user = Factory::getApplication()->getIdentity();

        if ($user === null || $user->guest || (int) $user->id <= 0) {
            return Principal::guest();
        }

        return Principal::user((int) $user->id);
    }

    protected function isGuest(): bool
    {
        return $this->principal()->isGuest();
    }
}
