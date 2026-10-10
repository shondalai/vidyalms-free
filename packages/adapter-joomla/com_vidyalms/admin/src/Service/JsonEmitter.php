<?php
/**
 * @package     VidyaLMS
 * @subpackage  Administrator
 * @copyright   Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace VidyaLMS\Component\VidyaLMS\Administrator\Service;

defined('_JEXEC') or die;

use Joomla\CMS\Application\CMSApplicationInterface;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;

/**
 * Writes one neutral ApiResponse onto the Joomla application and closes it:
 * no template, no document, no further rendering.
 *
 * Both browser-session surfaces of this component emit through here, the contract
 * passthrough and the upload endpoint, because the encoding is part of the
 * contract rather than part of either. The flags are the WordPress mount's
 * flags: byte-identical bodies across the two hosts is the whole promise, and
 * a second copy of this method is how one host starts escaping slashes.
 *
 * @since 1.0.0
 */
final class JsonEmitter
{
    public function __construct(
        private readonly CMSApplicationInterface $app,
    ) {
    }

    public function emit(ApiResponse $response): void
    {
        $body = '';

        if ($response->status !== 204 && $response->isBinary()) {
            // The neutral response owns this distinction. Do not sniff or
            // decode a JSON envelope: ordinary JSON may have the same fields.
            // This matches the API-token REST and WordPress emitters exactly.
            $body = $response->binaryBytes();
        } elseif ($response->status !== 204 && $response->data !== null) {
            $encoded = json_encode($response->data, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

            if ($encoded === false) {
                ComponentLog::error(
                    'A VidyaLMS JSON response could not be encoded: ' . json_last_error_msg(),
                );

                $response = ApiProblem::internal()->toResponse();
                $encoded  = (string) json_encode($response->data, \JSON_UNESCAPED_SLASHES);
            }

            $body = $encoded;
        }

        $this->app->allowCache(false);
        $this->app->setHeader('status', (string) $response->status, true);

        $hasContentType = false;

        foreach ($response->headers as $name => $value) {
            $this->app->setHeader($name, $value, true);

            if (strtolower($name) === 'content-type') {
                $hasContentType = true;
            }
        }

        if (!$hasContentType && $body !== '') {
            $this->app->setHeader('Content-Type', 'application/json', true);
        }

        $this->app->sendHeaders();

        if ($body !== '') {
            echo $body;
        }

        $this->app->close();
    }
}
