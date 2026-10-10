<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateRecord;
use Shondalai\VidyaLMS\Core\Application\Credentials\ListCertificates;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET /admin/certificates
 */
final class ListCertificatesHandler implements HandlerInterface
{
    public function __construct(
        private readonly ListCertificates $listCertificates,
        private readonly string $verificationBaseUrl,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $status = array_key_exists('status', $request->query) ? $request->queryString('status') : null;
        $search = $request->queryString('q');
        $courseId = array_key_exists('course_id', $request->query) ? $request->queryInt('course_id', 0) : null;
        $templateId = array_key_exists('template_id', $request->query) ? $request->queryInt('template_id', 0) : null;

        $page = $this->listCertificates->execute(
            $request->principal,
            $status,
            $courseId,
            $templateId,
            $search === '' ? null : $search,
            $request->queryString('sort', 'issued_at'),
            $request->queryString('order', 'desc'),
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 20),
        );

        $baseUrl = $this->verificationBaseUrl;

        return ApiResponse::ok([
            'items' => array_map(
                fn (CertificateRecord $record): array => $this->presenters->credentials->certificateRecord($record, $baseUrl),
                $page->items,
            ),
            'total' => $page->total,
            'page' => $page->page,
            'per_page' => $page->perPage,
        ]);
    }
}
