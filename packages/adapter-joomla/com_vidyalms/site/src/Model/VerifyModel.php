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
 * Public certificate verification.
 *
 * It calls the contract, GET /certificates/{code}/verify, rather than the use
 * case behind it, and that is a security decision rather than a stylistic one.
 * The verification page discloses a name against a course completion, so it is
 * rate limited by caller and marked noindex, and 13-learning-flow-and-credentials.md
 * requires both to be core middleware rather than a rule each adapter
 * remembers: a control written once per adapter covers only the routes that
 * adapter happens to own, and this page and the REST route are two transports
 * over one disclosure. Going through ApiKernel::handle() puts the HTML page
 * inside the same middleware chain as the JSON route, so a burst against
 * either is counted against the same bucket and the noindex header comes back
 * on the response rather than being retyped here.
 *
 * An unknown code is an ordinary negative answer from the core, never an
 * error, so this model never signals a failure that could be used to tell
 * existing codes from missing ones.
 *
 * @since 1.0.0
 */
final class VerifyModel extends KernelModel
{
    private const MAX_CODE_LENGTH = 64;

    private ?CoreRouteResult $result = null;

    protected function populateState(): void
    {
        $input = Factory::getApplication()->getInput();
        $code  = trim((string) $input->get('code', '', 'string'));

        $this->setState('certificate.code', substr($code, 0, self::MAX_CODE_LENGTH));
    }

    public function getCode(): string
    {
        return (string) $this->getState('certificate.code', '');
    }

    /**
     * Null only when no code was supplied at all, which is the empty form
     * state rather than a failed verification.
     */
    public function getResult(): ?CoreRouteResult
    {
        if ($this->result instanceof CoreRouteResult) {
            return $this->result;
        }

        $code = $this->getCode();

        if ($code === '') {
            return null;
        }

        return $this->result = CoreRoute::call(
            'GET',
            '/certificates/' . rawurlencode($code) . '/verify',
            $this->principal(),
        );
    }
}
