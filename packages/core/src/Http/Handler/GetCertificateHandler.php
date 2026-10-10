<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateRecords;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCreditRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusRepository;
use Shondalai\VidyaLMS\Core\Domain\Credentials\IssuanceRecord;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * GET /admin/certificates/{id}
 *
 * One certificate with everything an administrator needs to answer a question
 * about it: what it was rendered from, whether it renders from what it froze,
 * what the artefact hashes to, which credential format it was built in, what
 * credit it carries, which status list slots it holds, and both ends of its
 * supersession chain.
 *
 * snapshot_present is on the payload rather than inferred, because "this
 * certificate renders from the live template" is the one fact an
 * administrator cannot discover any other way and is exactly what the
 * fallback exists to make visible.
 *
 * This is the management view, not the public one. It carries ids the
 * verification page never does, and it is behind the manage capability for
 * that reason.
 */
final class GetCertificateHandler implements HandlerInterface
{
    public function __construct(
        private readonly CertificateRepository $certificates,
        private readonly CertificateRecords $records,
        private readonly AccessGuard $guard,
        private readonly ClockPort $clock,
        private readonly string $verificationBaseUrl,
        private readonly PresenterRegistry $presenters,
        private readonly ?CertificateCreditRepository $credits = null,
        private readonly ?CredentialStatusRepository $statusLists = null,
        /**
         * Where a published status list is served. Empty on a host that
         * configured no credential base, which the entry reports as a null
         * URL rather than as a relative one.
         */
        private readonly string $statusListBaseUrl = '',
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $this->guard->require($request->principal, Capability::MANAGE);

        $certificate = $this->certificates->findById($request->intPathParam('id'));

        if ($certificate === null) {
            throw ApiProblem::notFound();
        }

        $records = $this->records->forCertificates([$certificate], $this->clock->now());
        $record = $records[0] ?? throw ApiProblem::notFound();

        $body = $this->presenters->credentials->certificateRecord($record, $this->verificationBaseUrl);

        // Flat, because that is what the contract declares and what the
        // admin record dialog reads. Nesting these under provenance,
        // artefact and credential type-checked on both sides and matched on
        // neither: every field the screen reads came back undefined, so a
        // certificate that demonstrably had a snapshot reported "no
        // snapshot", which is the exact opposite of the invariant the screen
        // exists to show.
        $body['template_id'] = $certificate->templateId;
        $body['template_revision'] = $certificate->templateRevision;
        $body['renderer_key'] = $certificate->rendererKey;
        $body['engine_version'] = $certificate->engineVersion;
        $body['snapshot_present'] = $certificate->hasSnapshot();
        $body['artefact_available'] = $certificate->artefactPath !== null;
        $body['artefact_checksum'] = $certificate->artefactChecksum;
        $body['rendered_at'] = Presenter::dateTime($certificate->renderedAt);
        $body['credential_format'] = $certificate->credentialFormat;
        $body['proof_type'] = $certificate->proofType;
        $body['issuer_earner_id'] = $certificate->issuerEarnerId;
        $body['supersedes_id'] = $certificate->supersedesId;
        $body['superseded_by_id'] = $certificate->supersededById;
        $body['published'] = $certificate->published;
        $body['revocation_reason'] = $certificate->revocationReason;

        $body['credits'] = array_map(
            static fn (\Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCredit $credit): array => [
                'scheme' => $credit->scheme,
                'amount' => $credit->amount,
                'unit' => $credit->unit,
                'field_of_study' => $credit->fieldOfStudy,
                'contact_minutes' => $credit->contactMinutes,
                // The contract's own format, like every other date on this
                // response. RFC 3339 is confined to the payloads a relying
                // party parses with no SDK in between, and this is not one.
                'reported_at' => Presenter::dateTime($credit->reportedAt),
            ],
            $this->credits?->forCertificate($certificate->id) ?? [],
        );

        $base = $this->statusListBaseUrl;

        $body['status_entries'] = array_map(
            static fn (\Shondalai\VidyaLMS\Core\Domain\Credentials\CredentialStatusEntry $entry): array => [
                'purpose' => $entry->purpose,
                'list_id' => $entry->listUuid,
                'list_index' => $entry->index,
                // Null until a build publishes the list, which the contract
                // says in as many words: allocation is free and publication
                // is paid, so the slot exists long before the document does.
                'status_list_url' => $base === ''
                    ? null
                    : rtrim($base, '/') . '/' . rawurlencode($entry->listUuid),
            ],
            $this->statusLists?->entriesFor(IssuanceRecord::SUBJECT_CERTIFICATE, $certificate->id) ?? [],
        );

        return ApiResponse::ok($body);
    }
}
