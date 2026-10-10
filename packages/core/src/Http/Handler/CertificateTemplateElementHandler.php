<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\DeleteCertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Application\Credentials\UpdateCertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * PATCH  /manage/certificate-template-elements/{id}
 * DELETE /manage/certificate-template-elements/{id}
 *
 * A drag is a patch of posx and posy alone and a settings change is a patch
 * of the payload alone; both arrive here, because the alternative is a
 * designer that has to know which of two routes a given gesture belongs to.
 */
final class CertificateTemplateElementHandler implements HandlerInterface
{
    private const ROUTE = 'manage.certificate_template_element.write';

    public function __construct(
        private readonly UpdateCertificateTemplateElement $update,
        private readonly DeleteCertificateTemplateElement $delete,
        private readonly PresenterRegistry $presenters,
        private readonly CertificateTemplateWrite $write,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $elementId = $request->intPathParam('id');

        return $this->write->run($request, self::ROUTE . ':' . $elementId, function () use ($request, $elementId): ApiResponse {
            if ($request->method === 'DELETE') {
                $result = $this->delete->execute($request->principal, $elementId, CertificateTemplateWrite::deleteRevision($request));

                return ApiResponse::ok(['template_revision' => $result->revision]);
            }

            return ApiResponse::ok($this->presenters->certificateTemplates->elementMutation(
                $this->update->execute($request->principal, $elementId, $request->body),
            ));
        });
    }
}
