<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ApiPassthrough;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\ApiUpload;

/**
 * The administrator-application JSON passthrough of the /v1 contract, for the
 * admin screens and the instructor workbench.
 *
 * Joomla's administrator session is not its site session and carries its own
 * form token, so an administrator page cannot ride the site task. It rides
 * this one instead, on the same Service\ApiPassthrough, with the
 * administrator identity as the principal.
 *
 * There is deliberately no capability check here. The administrator
 * application has already refused anyone who may not reach the back end at
 * all, and beyond that the core's AccessGuard is the only thing that knows
 * which course a request addresses. A guard here would cover this task alone
 * and would be free to drift from the API application and the WordPress
 * mount, which is exactly what the contract forbids.
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
     * The file intake the workbench's picker posts to: task=api.upload.
     *
     * It sits here rather than on the contract because no bytes travel through
     * the contract, exactly as with imports. The same session token guards it,
     * and the core's AccessGuard decides who may author.
     *
     * @return void
     */
    public function upload()
    {
        (new ApiUpload($this->app, $this->input))->serve();
    }
}
