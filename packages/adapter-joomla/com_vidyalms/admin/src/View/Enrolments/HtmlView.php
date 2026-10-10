<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\View\Enrolments;

use VidyaLMS\Component\VidyaLMS\Administrator\View\AppView;

defined('_JEXEC') or die;

/**
 * The admin app mounted on the site-wide enrolment list.
 *
 * @since 1.0.0
 */
final class HtmlView extends AppView
{
    protected string $screen = 'enrolments';

    protected string $titleKey = 'COM_VIDYALMS_MANAGER_ENROLMENTS';

    protected string $titleIcon = 'users enrolments';
}
