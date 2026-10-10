<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\FeedbackIntake;

/**
 * The inbound delivery feedback endpoint: task=feedback.receive.
 *
 * index.php?option=com_vidyalms&task=feedback.receive&channel=email is a plain
 * site URL an ESP webhook or a feedback-mailbox forwarder can be pointed at.
 * Everything it does lives in Service\FeedbackIntake, which hands the raw body
 * and the per-channel shared secret to the core contract route.
 *
 * It is mounted on the site application only. The administrator application
 * has no reason to accept an unauthenticated machine post, and a second mount
 * would be a second door to the same room.
 *
 * @since 1.0.0
 */
final class FeedbackController extends BaseController
{
    /**
     * The contract: task=feedback.receive.
     *
     * @return void
     */
    public function receive()
    {
        (new FeedbackIntake($this->app, $this->input))->serve();
    }
}
