<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\View\Verify;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;
use Shondalai\VidyaLMS\Core\Http\Middleware\NoIndexMiddleware;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRouteResult;
use VidyaLMS\Component\VidyaLMS\Site\Model\VerifyModel;

/**
 * Public certificate verification.
 *
 * The page answers for every code, known or not, because it is the link
 * printed on a certificate and enumerating codes must reveal nothing. It is
 * deliberately **not** indexable: a name against a course completion is
 * personal data, so 13-learning-flow-and-credentials.md puts this page on the
 * noindex and rate limited list beside the credential routes and the
 * unsubscribe pair. Both controls come from the core middleware chain the
 * model's contract call runs through; this view only carries the header the
 * chain returned onto the host response and mirrors it as a meta tag, because
 * a crawler that ignores one usually honours the other.
 *
 * @since 1.0.0
 */
final class HtmlView extends BaseHtmlView
{
    public string $code = '';

    /**
     * The contract answer, or null when no code was submitted at all. A page
     * reads its fields tolerantly: the verification shape gains fields as
     * credential work lands, and this view must render correctly against a
     * build that has them and one that does not.
     */
    public ?CoreRouteResult $result = null;

    /**
     * @var array<string, mixed>
     */
    public array $verification = [];

    public Registry $params;

    public string $formAction = '';

    public int $itemId = 0;

    /**
     * True when the caller asked too often. The page then says so rather than
     * reporting a code as unverifiable, which would be a different and wrong
     * answer.
     */
    public bool $rateLimited = false;

    /**
     * True when this build could answer at all. False renders the "not
     * available" state rather than an error a visitor can do nothing about.
     */
    public bool $available = true;

    public function display($tpl = null): void
    {
        /** @var VerifyModel $model */
        $model = $this->getModel();
        $app   = Factory::getApplication();

        \VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig::forbidPageCache();
        $app->setHeader('Cache-Control', 'private, no-store, max-age=0', true);

        $this->params     = $app->getParams();
        $this->code       = $model->getCode();
        $this->result     = $model->getResult();
        $this->itemId     = (int) $app->getInput()->get('Itemid', 0, 'int');
        $this->formAction = Route::_('index.php?option=com_vidyalms&view=verify');

        if ($this->result !== null) {
            $this->rateLimited  = $this->result->rateLimited();
            $this->available    = !$this->result->routeMissing();
            $this->verification = $this->result->ok() ? $this->result->data : [];
        }

        $this->noIndex($this->result);

        $title = (string) $this->params->get('page_title', '');

        $this->setDocumentTitle($title !== '' ? $title : Text::_('COM_VIDYALMS_VERIFY_TITLE'));

        parent::display($tpl);
    }

    /**
     * Carry the core middleware's noindex onto this host's response.
     *
     * The value is whatever the chain set, never a literal typed here, so the
     * rule stays in one place. The fallback covers the one case the chain
     * cannot answer: a page reached with no code at all makes no contract call
     * and still discloses the existence of the surface, and a kernel this site
     * could not build must not fall back to indexable.
     */
    private function noIndex(?CoreRouteResult $result): void
    {
        $robots = $result?->header(NoIndexMiddleware::HEADER) ?? '';

        if ($robots === '') {
            $robots = NoIndexMiddleware::VALUE;
        }

        $app = Factory::getApplication();

        if (method_exists($app, 'setHeader')) {
            $app->setHeader(NoIndexMiddleware::HEADER, $robots, true);
        }

        $this->getDocument()->setMetaData('robots', $robots);
    }
}
