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
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ApiPassthrough;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ApiUpload;

/**
 * The site-application JSON passthrough of the /v1 contract, for the learner
 * apps running in the browser.
 *
 * Everything it does lives in Service\ApiPassthrough, which the administrator
 * application mounts under the identical task for the workbench and the admin
 * screens. Two sessions, two form tokens, one implementation: a difference
 * between the learner surface and the administrator surface could only be a
 * bug, so there is nowhere for one to appear.
 *
 * @since 1.0.0
 */
final class ApiController extends BaseController
{
    /**
     * The contract: task=api.route.
     *
     * @return void
     */
    public function route()
    {
        (new ApiPassthrough($this->app, $this->input))->serve();
    }

    /**
     * The file intake: task=api.upload, the same Service\ApiUpload the
     * administrator application mounts under the identical task.
     *
     * It is mounted on the site as well because the family is mounted twice or
     * not at all: an app running on a site page holds the site form token and
     * nothing else, and an endpoint that existed only in the administrator
     * would answer that app with an HTML login page.
     *
     * @return void
     */
    public function upload()
    {
        (new ApiUpload($this->app, $this->input))->serve();
    }
}
