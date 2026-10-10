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
use VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRoute;
use VidyaLMS\Component\VidyaLMS\Administrator\Service\CoreRouteResult;

/**
 * The unsubscribe page's two calls, and nothing else.
 *
 * Reading and acting are separate operations on purpose, and the separation is
 * a legal one rather than an architectural preference. Mail security scanners
 * follow every link in a message, which is exactly why RFC 8058 specifies a
 * POST for one-click: a GET that unsubscribed would turn a scanner into an
 * opt-out. So preview() is the only thing a GET may do, and act() is reachable
 * only from a POST.
 *
 * Nothing here demands anything beyond the token. CAN-SPAM bars asking for
 * more than an address and bars charging a fee, so there is no login check, no
 * CSRF token and no confirmation of identity: the signed token is the
 * authorization, and it is verified by the core.
 *
 * @since 1.0.0
 */
final class UnsubscribeModel extends KernelModel
{
    /**
     * The exact body RFC 8058 section 3.1 specifies, and the only one a mail
     * client sends. It is compared verbatim, because a receiver builds it
     * verbatim from the List-Unsubscribe-Post header value.
     */
    public const ONE_CLICK_FIELD = 'List-Unsubscribe';

    public const ONE_CLICK_VALUE = 'One-Click';

    /**
     * A token is an HMAC in URL-safe base64 with a payload in front of it.
     * Anything longer is not one, and truncating rather than rejecting keeps
     * the page's answer the same for a malformed token and an expired one.
     */
    private const MAX_TOKEN_LENGTH = 512;

    private ?CoreRouteResult $preview = null;

    private ?CoreRouteResult $outcome = null;

    protected function populateState(): void
    {
        $input = Factory::getApplication()->getInput();
        $token = trim((string) $input->get('token', '', 'string'));

        $this->setState('unsubscribe.token', substr($token, 0, self::MAX_TOKEN_LENGTH));
    }

    public function getToken(): string
    {
        return (string) $this->getState('unsubscribe.token', '');
    }

    /**
     * What this token would stop. Reads and writes nothing.
     */
    public function preview(): CoreRouteResult
    {
        if ($this->preview instanceof CoreRouteResult) {
            return $this->preview;
        }

        return $this->preview = CoreRoute::call(
            'GET',
            '/notifications/unsubscribe/' . rawurlencode($this->getToken()),
            $this->principal()
        );
    }

    /**
     * Acts on the token. Only ever called from a POST.
     *
     * Two bodies reach this: a mail client's one-click form, which carries the
     * single field and names no channels, and the human form, which may name
     * the channels to stop. Both go to the same contract path, because the
     * core has one rule and the page has no business holding a second one.
     *
     * @param list<string> $channels
     */
    public function act(array $channels): CoreRouteResult
    {
        if ($this->outcome instanceof CoreRouteResult) {
            return $this->outcome;
        }

        return $this->outcome = CoreRoute::call(
            'POST',
            '/notifications/unsubscribe/' . rawurlencode($this->getToken()),
            $this->principal(),
            $channels === [] ? [] : ['channels' => $channels]
        );
    }

    /**
     * Whether this request is the RFC 8058 one-click POST rather than the
     * human form. The two are told apart so the page can answer the machine
     * with the outcome and no markup it will never read, and the answer is
     * never a redirect: RFC 8058 forbids the sender returning one.
     */
    public function isOneClick(): bool
    {
        $input = Factory::getApplication()->getInput();

        return (string) $input->post->get(self::ONE_CLICK_FIELD, '', 'string') === self::ONE_CLICK_VALUE;
    }

    /**
     * The channels the human form asked to stop, or an empty list meaning
     * every channel of the token's category.
     *
     * @return list<string>
     */
    public function postedChannels(): array
    {
        $input = Factory::getApplication()->getInput();
        $raw   = $input->post->get('channels', [], 'array');

        $channels = [];

        foreach ((array) $raw as $channel) {
            if (\is_string($channel) && preg_match('/^[a-z0-9_.-]{1,64}$/', $channel) === 1) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }
}
