<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateRecords;
use Shondalai\VidyaLMS\Core\Application\Credentials\RevokeCertificate;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /admin/certificates/{id}/revoke
 *
 * The reason is required rather than optional. Revocation is permanent and
 * public, the confirmation dialog names the learner, the course and the
 * consequence, and a revocation with no recorded reason is an answer a
 * relying party cannot act on and an auditor cannot reconstruct. Open Badges
 * 2.0 supplies the vocabulary: issued in error, violation of conditions,
 * award reconsidered.
 */
final class RevokeCertificateHandler implements HandlerInterface
{
    private const ROUTE = 'admin.certificates.revoke';

    public function __construct(
        private readonly RevokeCertificate $revokeCertificate,
        private readonly CertificateRecords $records,
        private readonly ClockPort $clock,
        private readonly IdempotentExecution $idempotent,
        private readonly string $verificationBaseUrl,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $certificateId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $certificateId): ApiResponse {
            $reason = $request->body['reason'] ?? null;

            if (!\is_string($reason) || trim($reason) === '') {
                throw ApiProblem::validation([
                    'reason' => 'A revocation records why. Choose one of the documented reasons.',
                ]);
            }

            $certificate = $this->revokeCertificate->execute($request->principal, $certificateId, trim($reason));
            $records = $this->records->forCertificates([$certificate], $this->clock->now());
            $record = $records[0] ?? throw ApiProblem::notFound();

            return ApiResponse::ok($this->presenters->credentials->certificateRecord($record, $this->verificationBaseUrl));
        });
    }
}
