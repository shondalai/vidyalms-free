<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\ListMyCertificates;
use Shondalai\VidyaLMS\Core\Domain\Credentials\Certificate;
use Shondalai\VidyaLMS\Core\Domain\Credentials\CertificateCreditRepository;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * GET /me/certificates
 *
 * The learner's own credentials, with the status resolved against the clock
 * rather than read off a column: expired and not yet valid are derived, so a
 * clock change can never leave this list stale.
 */
final class ListMyCertificatesHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListMyCertificates $listMyCertificates,
        private readonly ?ClockPort $clock = null,
        private readonly string $credentialBaseUrl = '',
        private readonly ?CertificateCreditRepository $credits = null,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $now = $this->clock?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $page = $this->listMyCertificates->page(
            $request->principal,
            $now,
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 25),
            array_key_exists('course_id', $request->query) ? $request->queryInt('course_id', 0) : null
        );

        return ApiResponse::ok([
            'items' => array_map(
                fn (Certificate $certificate): array => Presenter::certificate(
                    $certificate,
                    $now,
                    $this->credentialBaseUrl,
                    '',
                    $this->credits?->forCertificate($certificate->id) ?? [],
                ),
                $page['items'],
            ),
            'total' => $page['total'], 'page' => $page['page'], 'per_page' => $page['per_page'],
        ])->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
