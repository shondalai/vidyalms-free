<?php

declare(strict_types=1);

/**
 * VidyaLMS: writing a core ApiResponse to the client verbatim.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Rest;

use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * Turns one neutral ApiResponse into a WordPress response whose body reaches
 * the client exactly as the core wrote it.
 *
 * WordPress would otherwise reshape it: _fields trims members out of the
 * payload, _envelope wraps it in a body/status/headers object, _jsonp wraps it
 * in a callback, and its own encoder escapes slashes and unicode where the
 * Joomla mount does not. Every one of those is a divergence from openapi.yaml
 * on a contract whose whole promise is that the two hosts answer identically,
 * so the bytes are written here and WordPress is told the request is served.
 * The data still rides on the WP_REST_Response, so an internal
 * rest_do_request() caller (which never reaches the filter) gets the same
 * payload as an HTTP caller.
 *
 * Both REST surfaces of this plugin emit through here, the /v1 contract and
 * the host's own upload endpoint, because the encoding is part of the contract
 * rather than part of either. A second copy of this class is how one surface
 * starts escaping slashes.
 *
 * One emitter belongs to one controller: it remembers the request it is
 * serving so a nested or parallel dispatch cannot pick up another's body.
 */
final class CoreBodyEmitter
{
    /**
     * The Joomla adapter encodes with these flags. Byte-identical bodies
     * across the two hosts is the point of the contract, so the flags are part
     * of it.
     */
    public const ENCODE_OPTIONS = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;

    private ?WP_REST_Request $serving = null;

    private string $servingBody = '';

    /**
     * @param (callable(string): void)|null $log where an encoding failure is
     *                                           reported; the client is told
     *                                           nothing beyond a bare problem
     */
    public function __construct(
        private $log = null,
    ) {
    }

    public function respond(WP_REST_Request $request, ApiResponse $response): WP_REST_Response
    {
        $body = $this->encode($response);

        if ($body === null) {
            $response = ApiProblem::internal()->toResponse();
            $body = (string) wp_json_encode($response->data, self::ENCODE_OPTIONS);
        }

        $rest = new WP_REST_Response($response->data, $response->status, $response->headers);
        $rest->header('X-Content-Type-Options', 'nosniff', true);

        if ($body !== '' && !self::declaresContentType($response)) {
            $rest->header('Content-Type', 'application/json', true);
        }

        $this->serving = $request;
        $this->servingBody = $body;

        add_filter('rest_pre_serve_request', [$this, 'serveCoreBody'], 10, 3);

        return $rest;
    }

    /**
     * @param mixed $served
     * @param mixed $result
     *
     * @return mixed
     */
    public function serveCoreBody($served, $result, WP_REST_Request $request)
    {
        if ($served || $request !== $this->serving) {
            return $served;
        }

        if ($this->servingBody !== '') {
            // A JSON document or a credential's own bytes, never markup:
            // escaping either would corrupt the contract, and the media type
            // the core declared is already on the response.
            echo $this->servingBody; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }

        return true;
    }

    /**
     * The encoded body, an empty string when there must be none, or null when
     * the payload could not be encoded at all.
     */
    private function encode(ApiResponse $response): ?string
    {
        if ($response->status === 204 || $response->data === null) {
            return '';
        }

        $binary = self::binaryBody($response);

        if ($binary !== null) {
            return $binary;
        }

        $encoded = wp_json_encode($response->data, self::ENCODE_OPTIONS);

        if (!\is_string($encoded)) {
            if (\is_callable($this->log)) {
                ($this->log)('a response could not be encoded as JSON: ' . json_last_error_msg());
            }

            return null;
        }

        return $encoded;
    }

    private static function declaresContentType(ApiResponse $response): bool
    {
        foreach (array_keys($response->headers) as $name) {
            if (strtolower((string) $name) === 'content-type') {
                return true;
            }
        }

        return false;
    }

    /**
     * The bytes of a binary response, or null when there are none.
     *
     * One of the free credential formats is a baked PNG, and `ApiResponse`
     * carries a decoded structure rather than a string, so a response holding
     * bytes says so on the object and hands them over whole.
     *
     * It used to be a sniff over the base64 envelope the core wrote inline,
     * and the Joomla mount carried a second copy of the same guess. Two
     * adapters had to agree forever on a rule written down nowhere, and a
     * drift between them does not fail a test: it serves base64 text where a
     * wallet expected a PNG. The core decides what a binary response is now,
     * and both mounts ask.
     *
     * The base64 envelope is still on `$response->data`, so an internal
     * rest_do_request() caller reading the payload sees what it saw before.
     */
    private static function binaryBody(ApiResponse $response): ?string
    {
        return $response->isBinary() ? $response->binaryBytes() : null;
    }
}
