<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatus;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Port\Storage\StoragePort;
use Shondalai\VidyaLMS\Core\Registry\Certificate\RenderedArtefact;

/**
 * GET /me/certificates/{id}/download
 *
 * The learner's own copy, as a signed expiring URL and never as a direct
 * path: private files go through the storage port and the only URL the core
 * ever emits for one is signed and expiring.
 *
 * A certificate that has not been rendered yet is a 409 rather than a 404,
 * because the certificate exists and the artefact does not: the render is a
 * queued job, and telling a learner their certificate does not exist while it
 * is waiting in a queue is the wrong answer to the right question.
 */
final class DownloadMyCertificateHandler implements HandlerInterface
{
    private const URL_TTL_SECONDS = 300;

    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly StoragePort $storage,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        if ($request->principal->isGuest()) {
            throw ApiProblem::unauthorized();
        }

        $certificate = $this->certificates->findById($request->intPathParam('id'));

        if ($certificate === null) {
            throw ApiProblem::notFound();
        }

        if (
            !$certificate->belongsTo($request->principal->userId)
            && !$this->guard->can($request->principal, Capability::MANAGE)
        ) {
            throw ApiProblem::notFound();
        }

        // A withdrawn or replaced credential is not handed out again. The
        // static file already in somebody's downloads folder is not this
        // platform's to recall, but minting a fresh signed URL for a
        // certificate the verification page reports as revoked is the
        // platform actively serving it.
        $status = $certificate->statusAt($this->clock->now());

        if ($status === CredentialStatus::Revoked || $status === CredentialStatus::Superseded) {
            throw ApiProblem::conflict(
                'This certificate is no longer in force.',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                array_filter([
                    // Not "status": RFC 9457 reserves that member for the
                    // HTTP status code, and an extension of the same name
                    // replaced it, so this 409 answered a string where the
                    // contract and the generated SDK both declare an integer.
                    'credential_status' => $status->value,
                    'superseded_by_id' => $certificate->supersededById,
                ], static fn (mixed $value): bool => $value !== null),
            );
        }

        if ($certificate->artefactPath === null) {
            throw ApiProblem::conflict('This certificate has not been rendered yet.', ['artefact_status' => 'not_rendered']);
        }

        $path = $certificate->artefactPath;
        // This is persisted output metadata, not the current renderer's type:
        // RenderCertificateTask chooses the extension from the returned artifact.
        // Validate before consulting storage or minting a signed URL; never expose
        // a malformed logical path as a suggested download filename.
        if (strlen($path) > 1024 || !str_starts_with($path, 'certificates/')) {
            throw ApiProblem::conflict('The stored certificate artifact needs repair.', ['artefact_status' => 'invalid_path']);
        }
        foreach (explode('/', $path) as $segment) {
            if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $segment) !== 1) {
                throw ApiProblem::conflict('The stored certificate artifact needs repair.', ['artefact_status' => 'invalid_path']);
            }
        }
        if (!$this->storage->exists($path)) {
            throw ApiProblem::conflict('The stored certificate artifact is unavailable.', ['artefact_status' => 'missing']);
        }
        $filename = basename($path);
        $mediaType = RenderedArtefact::mediaTypeFor(pathinfo($filename, PATHINFO_EXTENSION)) ?? 'application/octet-stream';

        return ApiResponse::ok([
            'url' => $this->storage->signedUrl($path, self::URL_TTL_SECONDS),
            'expires_at' => Presenter::dateTime($this->clock->now()->modify('+' . self::URL_TTL_SECONDS . ' seconds')),
            'media_type' => $mediaType,
            'filename' => $filename,
            'expires_in' => self::URL_TTL_SECONDS,
            'checksum' => $certificate->artefactChecksum,
            // The contract's own datetime format. RFC 3339 is confined to the
            // payloads a relying party parses with no SDK in between.
            'rendered_at' => Presenter::dateTime($certificate->renderedAt),
        ])->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
