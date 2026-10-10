<?php
/**
 * @package     VidyaLMS
 * @subpackage  Site
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Site\View\Unsubscribe;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\Registry\Registry;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRouteResult;
use VidyaLMS\Component\VidyaLMS\Site\Model\UnsubscribeModel;
use VidyaLMS\Component\VidyaLMS\Site\Service\MountConfig;

/**
 * The unsubscribe landing page and the RFC 8058 one-click endpoint, at one URL.
 *
 * One URL serves both because that is what the header pair says: a mail client
 * that supports one-click POSTs to the URI in List-Unsubscribe, and a person
 * who clicks the link GETs the same URI. Splitting them would put an address a
 * receiver cannot use in the header.
 *
 * Four rules this page exists to keep:
 *
 * - **A GET acts on nothing.** It renders a form. Mail security scanners
 *   follow links, and a GET that unsubscribed would turn a scanner into an
 *   opt-out, which is the whole reason RFC 8058 specifies a POST.
 * - **Nothing beyond the token is required.** No login, no CSRF token, no
 *   address to confirm and no fee: CAN-SPAM bars demanding any of them, and
 *   PECR and CASL give no shorter window in which to honour the request.
 * - **The page is never cached.** It is a guest page whose content is keyed to
 *   one person's token, so a cached copy would show one recipient's
 *   subscription to the next visitor. Joomla's page cache is refused through
 *   the onPageCacheIsExcluded listener MountConfig::forbidPageCache() adds, so
 *   no copy is ever stored, and the browser and any proxy through
 *   allowCache(false) and an explicit no-store.
 * - **The POST answers with the outcome and never a redirect.** RFC 8058
 *   forbids the sender returning an HTTPS redirect to a one-click POST.
 *
 * @since 1.0.0
 */
final class HtmlView extends BaseHtmlView
{
    public string $token = '';

    public ?CoreRouteResult $preview = null;

    public ?CoreRouteResult $outcome = null;

    public bool $acted = false;

    public bool $oneClick = false;

    public Registry $params;

    public string $formAction = '';

    public function display($tpl = null): void
    {
        /** @var UnsubscribeModel $model */
        $model = $this->getModel();
        $app   = Factory::getApplication();

        $this->params   = $app->getParams();
        $this->token    = $model->getToken();
        $this->oneClick = $model->isOneClick();

        // The form posts to the page's own URL, which is the URI the mail
        // carried, so a mail client's one-click POST and the form's post land
        // in the same place.
        $this->formAction = Route::_(
            'index.php?option=com_vidyalms&view=unsubscribe&token=' . urlencode($this->token)
        );

        $method = strtoupper((string) $app->getInput()->getMethod());

        if ($method === 'POST' && $this->token !== '') {
            $this->acted   = true;
            $this->outcome = $model->act($model->postedChannels());
        } else {
            $this->preview = $model->preview();
        }

        $this->noCache($app);

        if ($this->oneClick && $this->acted) {
            $this->answerOneClick($app);

            return;
        }

        $title = (string) $this->params->get('page_title', '');

        $this->setDocumentTitle($title !== '' ? $title : Text::_('COM_VIDYALMS_UNSUBSCRIBE_TITLE'));

        parent::display($tpl);
    }

    /**
     * A mail client's one-click POST reads a status code and nothing else, so
     * it is answered with the contract's own JSON rather than a rendered page
     * it will discard. Still not a redirect, which RFC 8058 forbids.
     */
    private function answerOneClick(object $app): void
    {
        $outcome = $this->outcome;
        $status  = $outcome === null ? 503 : $outcome->status;
        $body    = $outcome === null ? [] : $outcome->data;

        $app->setHeader('Status', (string) $status, true);
        $app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $app->sendHeaders();

        echo json_encode($body, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        $app->close();
    }

    /**
     * A page keyed to one person's token may not be cached anywhere.
     *
     * MountConfig::forbidPageCache() is the same refusal the mount pages
     * already use: allowCache(false) for the browser and any proxy, plus an
     * onPageCacheIsExcluded listener so Joomla's own page cache never stores a
     * copy. Nothing is stored, so nothing stale can be served back on a later
     * request before this view runs.
     */
    private function noCache(object $app): void
    {
        MountConfig::forbidPageCache();

        $app->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private', true);
        $app->setHeader('Pragma', 'no-cache', true);

        // A page that discloses which category a person is subscribed to has
        // no business in a search index.
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
    }
}
