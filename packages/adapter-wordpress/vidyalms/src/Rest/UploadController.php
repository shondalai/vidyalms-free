<?php

declare(strict_types=1);

/**
 * VidyaLMS: the host's own file intake for the React apps.
 *
 * @package   VidyaLMS
 * @copyright Copyright (C) 2026 BulaSikku Technologies Private Limited.
 * @license   GNU General Public License version 3 or later
 */

namespace Shondalai\VidyaLMS\WordPress\Rest;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Upload\UploadIntake;
use Shondalai\VidyaLMS\WordPress\Files\UploadLimits;
use Shondalai\VidyaLMS\WordPress\Files\UploadPolicy;
use WP_REST_Request;
use WP_REST_Response;

defined('ABSPATH') || exit;

/**
 * The host's file intake for the React apps: one multipart file in, one stored
 * file out, as JSON.
 *
 * ## Why it is not in vidyalms/v1
 *
 * That namespace is the shared contract, and openapi.yaml is its only
 * definition: a route this plugin invented there would answer on WordPress and
 * 404 on Joomla, which is the one thing the contract exists to prevent. No
 * bytes travel through the contract by design, exactly as with imports:
 * multipart is a CMS concern, the adapter stores the upload through the
 * storage port, and only the private path (or the public URL) travels on into
 * a use case.
 *
 * So this lives in the plugin's own namespace, vidyalms-host/v1, and the mount
 * config hands the apps its URL outright rather than letting the SDK derive
 * one. The Joomla adapter does the same thing in its own idiom: task=api.upload
 * beside task=api.route, not a contract path.
 *
 * ## Who may upload
 *
 * The core's AccessGuard answers, not a WordPress capability read here: a
 * caller holding vidyalms.teach or vidyalms.manage may author a course, and
 * authoring is what a file picker is for. WpAuthorization is what maps those
 * onto this site's roles, so the answer is the same one every use case gets.
 *
 * The per-course rule does not apply and could not: an upload happens while a
 * lesson is being drafted, before it names a course at all. What the upload
 * buys is a stored path, and a path only becomes reachable when a use case
 * that does apply the per-course rule accepts it into a lesson.
 *
 * Authentication is the browser session, exactly as for the contract: cookie
 * auth needs the wp_rest nonce, and without a valid one WordPress leaves the
 * request a guest, which this endpoint answers 401.
 */
final class UploadController
{
    /**
     * The plugin's own namespace. Deliberately not vidyalms/v1.
     */
    public const REST_NAMESPACE = 'vidyalms-host/v1';

    public const ROUTE = '/upload';

    /**
     * The multipart field the file arrives in, and the field naming which
     * purpose it is for. The same two names the Joomla task reads.
     */
    public const FILE_FIELD = 'file';

    public const PURPOSE_FIELD = 'purpose';

    private ?\Closure $kernelResolver;

    private ?\Closure $portsResolver;

    private CoreBodyEmitter $emitter;

    /**
     * The resolvers are the plugin bootstrap's way to the one shared kernel
     * and port bundle. Left out, the controller falls back to the global
     * accessors at call time, which is what lets it be constructed in a test
     * without WordPress.
     *
     * @param (callable(): CoreKernel)|null $kernelResolver
     * @param (callable(): Ports)|null      $portsResolver
     */
    public function __construct(?callable $kernelResolver = null, ?callable $portsResolver = null)
    {
        $this->kernelResolver = $kernelResolver === null ? null : \Closure::fromCallable($kernelResolver);
        $this->portsResolver = $portsResolver === null ? null : \Closure::fromCallable($portsResolver);
        $this->emitter = new CoreBodyEmitter(function (string $message): void {
            $this->log('upload ' . $message);
        });
    }

    /**
     * @param (callable(): CoreKernel)|null $kernelResolver
     * @param (callable(): Ports)|null      $portsResolver
     */
    public static function boot(?callable $kernelResolver = null, ?callable $portsResolver = null): self
    {
        $controller = new self($kernelResolver, $portsResolver);

        add_action('rest_api_init', [$controller, 'registerRoutes']);
        add_action('admin_notices', [\Shondalai\VidyaLMS\WordPress\Files\PrivateStorageNotice::class, 'render']);

        return $controller;
    }

    public function registerRoutes(): void
    {
        register_rest_route(
            self::REST_NAMESPACE,
            self::ROUTE,
            [
                [
                    'methods' => 'POST',
                    'callback' => [$this, 'handle'],
                    'permission_callback' => [$this, 'permitCoreToDecide'],
                ],
            ],
        );
    }

    /**
     * Explicit REST ingress guard. The handler repeats the same core permission
     * check for direct callers. Learner purposes require a login; authoring
     * purposes require teach or manage. Resolver failures deny access.
     */
    public function permitCoreToDecide(WP_REST_Request $request): bool|\WP_Error
    {
        try {
            $this->requirePermission($this->principal(), $this->purposeKey($request), $this->organisationId($request));
            return true;
        } catch (ApiProblem $problem) {
            return new \WP_Error('vidyalms_upload_forbidden', $problem->detail ?? $problem->title, ['status' => $problem->status]);
        } catch (\Throwable $failure) {
            $this->log('upload permission failure: ' . $failure->getMessage());
            return new \WP_Error('vidyalms_upload_unavailable', 'Upload permission could not be verified.', ['status' => 500]);
        }
    }

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        return $this->emitter->respond($request, $this->run($request));
    }

    /**
     * Never throws: an escaped throwable is a 500 problem with nothing of its
     * own in the body, and the detail goes to the debug log.
     */
    private function run(WP_REST_Request $request): ApiResponse
    {
        try {
            $principal = $this->principal();
            $purpose = $this->purposeKey($request);

            $this->requirePermission($principal, $purpose, $this->organisationId($request));

            $file = $this->uploadedFile($request);

            $stored = $this->intake()->store(
                $this->readUpload($file, $purpose),
                (string) ($file['name'] ?? ''),
                $purpose,
                $principal->userId,
                UploadLimits::phpCeilingBytes(),
            );

            return ApiResponse::created($stored->toArray());
        } catch (ApiProblem $problem) {
            return $problem->toResponse();
        } catch (\Throwable $failure) {
            $this->log(sprintf(
                'failure: %s in %s:%d',
                $failure->getMessage(),
                $failure->getFile(),
                $failure->getLine()
            ));

            return ApiProblem::internal()->toResponse();
        }
    }

    /**
     * 401 for a guest and 403 for a signed-in caller who may not author, which
     * is the order and the vocabulary the contract uses everywhere else. The
     * rule itself is the core's AuthoriseUpload use case; this only translates
     * its refusal. It was an identical constant and loop here and in the
     * Joomla endpoint, which is one rule written twice.
     */
    private function requirePermission(Principal $principal, string $purpose, ?int $organisationId = null): void
    {
        if ($principal->isGuest()) {
            throw ApiProblem::unauthorized();
        }

        try {
            $this->kernel()->authoriseUpload->execute($principal, $purpose, $organisationId);
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException) {
            throw ApiProblem::notFound();
        } catch (NotAuthorizedException) {
            throw ApiProblem::forbidden('You may not upload files to this site.');
        }
    }

    /**
     * The bytes, once this host has proved they came from an upload.
     * is_uploaded_file is what stops a crafted tmp_name naming a file of the
     * server's own, and it is the reason the bytes are read here rather than
     * inside the shared intake.
     *
     * @param array<string, mixed> $file
     */
    private function readUpload(array $file, string $purposeKey): string
    {
        $error = (int) ($file['error'] ?? \UPLOAD_ERR_NO_FILE);

        if ($error !== \UPLOAD_ERR_OK) {
            // Exception arguments are data; escape at the HTTP/template boundary.
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw ApiProblem::validation(['file' => self::uploadErrorMessage($error)]);
        }

        $temporary = (string) ($file['tmp_name'] ?? '');

        if ($temporary === '' || !is_uploaded_file($temporary)) {
            throw ApiProblem::validation(['file' => 'No file was uploaded.']);
        }

        $purpose = UploadPolicy::forSite()->purpose($purposeKey);
        $storage = $this->ports()->storage;
        if ($purpose === null) {
            throw ApiProblem::validation(['purpose' => 'Unknown upload purpose.']);
        }
        if (!$storage instanceof \Shondalai\VidyaLMS\WordPress\Port\WpStorage) {
            throw ApiProblem::internal();
        }
        return \Shondalai\VidyaLMS\WordPress\Files\WpUploadReceiver::read(
            $file, $purpose, $storage, UploadLimits::phpCeilingBytes(),
        );
    }

    /**
     * A transport failure is reported as itself rather than as "no file",
     * because "the server has no temporary folder" and "you chose nothing"
     * are different problems with different fixes.
     */
    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            \UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            \UPLOAD_ERR_INI_SIZE,
            \UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server accepts.',
            \UPLOAD_ERR_PARTIAL => 'The upload did not finish.',
            default => 'The upload failed on the server.',
        };
    }

    /**
     * The one file, from the one field. A request that sent several, or none,
     * is a client that did not read the endpoint.
     *
     * @return array<string, mixed>
     */
    private function uploadedFile(WP_REST_Request $request): array
    {
        $files = $request->get_file_params();
        $file = $files[self::FILE_FIELD] ?? null;

        if (!\is_array($file) || $file === []) {
            throw ApiProblem::validation(['file' => 'No file was uploaded.']);
        }

        // PHP hands back parallel arrays when the field name carried brackets.
        // One file per request keeps the answer a single object, which is what
        // the picker appends.
        if (isset($file['name']) && \is_array($file['name'])) {
            throw ApiProblem::validation(['file' => 'Upload one file per request.']);
        }

        return $file;
    }

    private function organisationId(WP_REST_Request $request): ?int
    {
        $id = $request->get_param('organisation_id');
        return (\is_int($id) || (\is_string($id) && ctype_digit($id))) && (int) $id > 0 ? (int) $id : null;
    }

    private function purposeKey(WP_REST_Request $request): string
    {
        $purpose = $request->get_param(self::PURPOSE_FIELD);

        if (!\is_string($purpose)) {
            return '';
        }

        // Reduced to the shape a purpose key may have, so the refusal names
        // what was asked for rather than echoing whatever was sent.
        return strtolower((string) preg_replace('/[^A-Za-z0-9_-]/', '', $purpose));
    }

    private function intake(): UploadIntake
    {
        $ports = $this->ports();

        return new UploadIntake(UploadPolicy::forSite(), $ports->storage, $ports->random);
    }

    private function principal(): Principal
    {
        $userId = (int) get_current_user_id();

        return $userId > 0 ? Principal::user($userId) : Principal::guest();
    }

    private function kernel(): CoreKernel
    {
        if ($this->kernelResolver !== null) {
            $kernel = ($this->kernelResolver)();

            if ($kernel instanceof CoreKernel) {
                return $kernel;
            }
        } elseif (function_exists('vidyalms_kernel')) {
            $kernel = vidyalms_kernel();

            if ($kernel instanceof CoreKernel) {
                return $kernel;
            }
        }

        throw new \RuntimeException('The VidyaLMS core kernel is not available on this request.');
    }

    private function ports(): Ports
    {
        if ($this->portsResolver !== null) {
            $ports = ($this->portsResolver)();

            if ($ports instanceof Ports) {
                return $ports;
            }
        } elseif (function_exists('vidyalms_ports')) {
            $ports = vidyalms_ports();

            if ($ports instanceof Ports) {
                return $ports;
            }
        }

        throw new \RuntimeException('The VidyaLMS ports are not available on this request.');
    }

    /**
     * A 500 has to be recoverable from a debug log, and nothing about it may
     * reach the client. Silent unless the site owner asked for debugging.
     */
    private function log(string $message): void
    {
        if (!\defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }

        error_log('[vidyalms] ' . $message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
