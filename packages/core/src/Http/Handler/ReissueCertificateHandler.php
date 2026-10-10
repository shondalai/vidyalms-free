<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateRecords;
use Shondalai\VidyaLMS\Core\Application\Credentials\ReissueCertificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCreditRepository;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * POST /admin/certificates/{id}/reissue
 *
 * One correction, as a supersession. The response is the new certificate, and
 * it names what was carried forward so the caller can see that a compliance
 * figure did not quietly vanish with the row it replaced.
 */
final class ReissueCertificateHandler implements HandlerInterface
{
    private const ROUTE = 'admin.certificates.reissue';

    public function __construct(
        private readonly ReissueCertificate $reissueCertificate,
        private readonly CertificateRecords $records,
        private readonly ClockPort $clock,
        private readonly IdempotentExecution $idempotent,
        private readonly string $verificationBaseUrl,
        private readonly PresenterRegistry $presenters,
        private readonly CertificateCreditRepository $credits,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $certificateId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $certificateId): ApiResponse {
            $renew = ($request->body['renew'] ?? false) === true;
            $certificate = $this->reissueCertificate->execute($request->principal, $certificateId, $renew);

            $records = $this->records->forCertificates([$certificate], $this->clock->now());
            $record = $records[0] ?? throw ApiProblem::notFound();

            $body = $this->presenters->credentials->certificateRecord($record, $this->verificationBaseUrl);
            $body['supersedes_id'] = $certificate->supersedesId;
            // Stated rather than implied, because a reissue that quietly drops
            // a field is an evidentiary defect and the caller has no other way
            // to see that it did not.
            $body['carried_forward'] = [
                'completed_at' => Presenter::dateTime($certificate->completedAt),
                'valid_from' => Presenter::dateTime($certificate->validFrom),
                'expires_at' => Presenter::dateTime($certificate->expiresAt),
                'recipient_identity_hash' => $certificate->recipientIdentityHash !== null,
                'issuer_earner_id' => $certificate->issuerEarnerId,
                'credits' => \count($this->credits->forCertificate($certificate->id)),
            ];

            return ApiResponse::created($body);
        });
    }
}
