<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateRecords;
use Shondalai\VidyaLMS\Core\Application\Credentials\SuspendCertificate;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * POST /admin/certificates/{id}/suspend
 * POST /admin/certificates/{id}/reinstate
 *
 * The reversible half of the revoke ceremony. It is two routes over one
 * handler because they are one decision in two directions and splitting the
 * class would duplicate the idempotency wrapper and the presenter call for a
 * single boolean.
 */
final class SuspendCertificateHandler implements HandlerInterface
{
    public function __construct(
        private readonly SuspendCertificate $suspendCertificate,
        private readonly CertificateRecords $records,
        private readonly ClockPort $clock,
        private readonly IdempotentExecution $idempotent,
        private readonly string $verificationBaseUrl,
        private readonly PresenterRegistry $presenters,
        private readonly bool $suspend,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $certificateId = $request->intPathParam('id');
        $route = $this->suspend ? 'admin.certificates.suspend' : 'admin.certificates.reinstate';

        return $this->idempotent->run($request, $route, function () use ($request, $certificateId): ApiResponse {
            $reason = $request->body['reason'] ?? null;

            try {
                $certificate = $this->suspend
                    ? $this->suspendCertificate->execute(
                        $request->principal,
                        $certificateId,
                        \is_string($reason) && $reason !== '' ? $reason : null,
                    )
                    : $this->suspendCertificate->reinstate($request->principal, $certificateId);
            } catch (\DomainException $refusal) {
                // A revoked or superseded credential cannot be put on hold:
                // revocation is permanent and supersession is a chain, and
                // stepping back from either would rewrite an evidentiary
                // record.
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ApiProblem::conflict($refusal->getMessage());
            }

            $records = $this->records->forCertificates([$certificate], $this->clock->now());
            $record = $records[0] ?? throw ApiProblem::notFound();

            return ApiResponse::ok(
                $this->presenters->credentials->certificateRecord($record, $this->verificationBaseUrl),
            );
        });
    }
}
