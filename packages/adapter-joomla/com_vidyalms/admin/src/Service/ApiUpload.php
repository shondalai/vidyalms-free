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
use Joomla\CMS\Factory;
use Joomla\CMS\Session\Session;
use Joomla\Input\Input;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Ports;
use Shondalai\VidyaLMS\Core\Upload\UploadIntake;

/**
 * The host's file intake for the React apps: one multipart file in, one stored
 * file out, as JSON.
 *
 * It is not on the /v1 contract and must never be. No bytes travel through
 * that contract by design, exactly as with imports: multipart is a CMS
 * concern, the adapter stores the upload through the storage port, and only
 * the private path (or the public URL) travels on into a use case. What this
 * class adds to UploadIntake is the transport: the session token, the
 * multipart parsing, the capability check and the emit.
 *
 * It rides the same passthrough family as the contract, task=api.upload beside
 * task=api.route, mounted twice for the same reason: Joomla's administrator
 * session is not its site session and carries its own form token, so each
 * application mounts its own task and both land here.
 *
 * ## Who may upload
 *
 * The core's AuthoriseUpload use case answers, not Joomla's ACL and not this
 * class. The rule used to be a private constant and a loop here and an
 * identical constant and loop in the WordPress controller, which is a rule
 * written twice: a host could widen it with nothing failing. It is one use
 * case now, tested in the core suite, and this class only turns its refusal
 * into the right status.
 *
 * @since 1.0.0
 */
final class ApiUpload
{
    /**
     * The header every request must carry, named by the passthrough so the two
     * halves of the family cannot disagree about it.
     */
    public const CSRF_HEADER = ApiPassthrough::CSRF_HEADER;

    /**
     * The multipart field the file arrives in, and the field naming which
     * purpose it is for.
     */
    public const FILE_FIELD = 'file';

    public const PURPOSE_FIELD = 'purpose';

    public function __construct(
        private readonly CMSApplicationInterface $app,
        private readonly Input $input,
    ) {
    }

    /**
     * Runs one upload and writes the answer, then closes the application.
     */
    public function serve(): void
    {
        $response = $this->csrfTokenValid()
            ? $this->run()
            : ApiProblem::forbidden('The CSRF token is missing or invalid.')->toResponse();

        (new JsonEmitter($this->app))->emit($response);
    }

    /**
     * The header comparison is the passthrough's, for the passthrough's
     * reason: checkToken() answers an expired session with an HTML redirect,
     * and a JSON surface must answer JSON.
     */
    private function csrfTokenValid(): bool
    {
        $sent = (string) $this->input->server->get('HTTP_X_CSRF_TOKEN', '', 'alnum');

        return $sent !== '' && hash_equals(Session::getFormToken(), $sent);
    }

    /**
     * Never throws: an escaped throwable is a 500 problem with nothing of its
     * own in the body, and the detail goes to the component log.
     */
    private function run(): ApiResponse
    {
        try {
            if (strtoupper((string) $this->input->getMethod()) !== 'POST') {
                return (new ApiProblem(405, 'Method not allowed', 'This endpoint accepts POST.'))->toResponse();
            }

            $principal = $this->principal();

            $purpose = (string) $this->input->post->get(self::PURPOSE_FIELD, '', 'cmd');

            $this->requirePermission($principal, $purpose, $this->input->post->getInt('organisation_id', 0));

            $file = $this->uploadedFile();

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
            ComponentLog::error(sprintf(
                'Upload endpoint failure: %s in %s:%d',
                $failure->getMessage(),
                $failure->getFile(),
                $failure->getLine(),
            ));

            return ApiProblem::internal()->toResponse();
        }
    }

    /**
     * 401 for a guest and 403 for a signed-in caller who may not author, which
     * is the order and the vocabulary the contract uses everywhere else. The
     * rule itself is the core's; this only translates its refusal.
     */
    private function requirePermission(Principal $principal, string $purpose, ?int $organisationId = null): void
    {
        if ($principal->isGuest()) {
            throw ApiProblem::unauthorized();
        }

        try {
            KernelFactory::kernel()->authoriseUpload->execute($principal, $purpose, $organisationId);
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
            throw ApiProblem::validation(['file' => self::uploadErrorMessage($error)]);
        }

        $temporary = (string) ($file['tmp_name'] ?? '');

        if ($temporary === '' || !is_uploaded_file($temporary)) {
            throw ApiProblem::validation(['file' => 'No file was uploaded.']);
        }

        $purpose = UploadPolicy::forSite()->purpose($purposeKey);
        if ($purpose === null) {
            throw ApiProblem::validation(['purpose' => 'Unknown upload purpose.']);
        }
        $hostCeiling = UploadLimits::phpCeilingBytes();
        return \Shondalai\VidyaLMS\Core\Upload\UploadedBytes::read(
            $temporary, $hostCeiling > 0 ? min($purpose->maxBytes, $hostCeiling) : $purpose->maxBytes,
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
            \UPLOAD_ERR_NO_FILE   => 'No file was uploaded.',
            \UPLOAD_ERR_INI_SIZE,
            \UPLOAD_ERR_FORM_SIZE => 'The file is larger than this server accepts.',
            \UPLOAD_ERR_PARTIAL   => 'The upload did not finish.',
            default               => 'The upload failed on the server.',
        };
    }

    /**
     * The one file, from the one field. A request that sent several, or none,
     * is a client that did not read the endpoint.
     *
     * @return array<string, mixed>
     */
    private function uploadedFile(): array
    {
        $file = $this->input->files->get(self::FILE_FIELD, null, 'raw');

        if (!\is_array($file) || $file === []) {
            throw ApiProblem::validation(['file' => 'No file was uploaded.']);
        }

        // Joomla hands back a list when the field name carried brackets. One
        // file per request keeps the answer a single object, which is what the
        // picker appends.
        if (isset($file[0])) {
            throw ApiProblem::validation(['file' => 'Upload one file per request.']);
        }

        return $file;
    }

    private function intake(): UploadIntake
    {
        $ports = Factory::getContainer()->get(Ports::class);

        if (!$ports instanceof Ports) {
            throw ApiProblem::internal();
        }

        return new UploadIntake(UploadPolicy::forSite(), $ports->storage, $ports->random);
    }

    private function principal(): Principal
    {
        $identity = $this->app->getIdentity();
        $userId   = $identity === null || $identity->guest ? 0 : (int) $identity->id;

        return $userId > 0 ? Principal::user($userId) : Principal::guest();
    }
}
