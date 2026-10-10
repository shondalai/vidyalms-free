<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\ArchiveCertificateTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\GetCertificateTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\UpdateCertificateTemplate;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET    /manage/certificate-templates/{id}
 * PATCH  /manage/certificate-templates/{id}
 * DELETE /manage/certificate-templates/{id}
 *
 * The whole aggregate on the read, the page-independent settings on the
 * patch, and a retirement rather than a destruction on the delete: a template
 * with issued certificates is archived and its rows stay, because those
 * certificates keep verifying.
 */
final class CertificateTemplateHandler implements HandlerInterface
{
    private const ROUTE = 'manage.certificate_template.write';

    public function __construct(
        private readonly GetCertificateTemplate $get,
        private readonly UpdateCertificateTemplate $update,
        private readonly ArchiveCertificateTemplate $archive,
        private readonly PresenterRegistry $presenters,
        private readonly CertificateTemplateWrite $write,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $templateId = $request->intPathParam('id');

        if ($request->method === 'GET') {
            return ApiResponse::ok($this->presenters->certificateTemplates->templateDetail(
                $this->get->execute($request->principal, $templateId),
            ));
        }

        return $this->write->run($request, self::ROUTE . ':' . $templateId, function () use ($request, $templateId): ApiResponse {
            $view = $request->method === 'DELETE'
                ? $this->archive->execute($request->principal, $templateId, CertificateTemplateWrite::deleteRevision($request))
                : $this->update->execute($request->principal, $templateId, $request->body);

            return ApiResponse::ok($this->presenters->certificateTemplates->template($view));
        });
    }
}
