<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\DeleteCertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Application\Credentials\UpdateCertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * PATCH  /manage/certificate-template-pages/{id}
 * DELETE /manage/certificate-template-pages/{id}
 *
 * A page is addressed by its own id, because a designer saves one page far
 * more often than it saves a whole template.
 */
final class CertificateTemplatePageHandler implements HandlerInterface
{
    private const ROUTE = 'manage.certificate_template_page.write';

    public function __construct(
        private readonly UpdateCertificateTemplatePage $update,
        private readonly DeleteCertificateTemplatePage $delete,
        private readonly PresenterRegistry $presenters,
        private readonly CertificateTemplateWrite $write,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $pageId = $request->intPathParam('id');

        return $this->write->run($request, self::ROUTE . ':' . $pageId, function () use ($request, $pageId): ApiResponse {
            if ($request->method === 'DELETE') {
                $result = $this->delete->execute($request->principal, $pageId, CertificateTemplateWrite::deleteRevision($request));

                return ApiResponse::ok(['template_revision' => $result->revision]);
            }

            return ApiResponse::ok($this->presenters->certificateTemplates->pageMutation(
                $this->update->execute($request->principal, $pageId, $request->body),
            ));
        });
    }
}
