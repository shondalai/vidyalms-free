<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\CreateCertificateTemplate;
use Shondalai\VidyaLMS\Core\Application\Credentials\ListCertificateTemplates;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET  /manage/certificate-templates
 * POST /manage/certificate-templates
 *
 * The gallery and the "new template" gesture. The list carries batched counts
 * and first-page sample artwork rather than the full editable aggregates.
 */
final class CertificateTemplatesHandler implements HandlerInterface
{
    private const ROUTE = 'manage.certificate_templates.create';

    public function __construct(
        private readonly ListCertificateTemplates $list,
        private readonly CreateCertificateTemplate $create,
        private readonly PresenterRegistry $presenters,
        private readonly CertificateTemplateWrite $write,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        if ($request->method === 'POST') {
            return $this->write->run($request, self::ROUTE, function () use ($request): ApiResponse {
                return ApiResponse::created($this->presenters->certificateTemplates->template(
                    $this->create->execute($request->principal, $request->body),
                ));
            });
        }

        $state = $request->queryString('state');
        $format = $request->queryString('format');

        return ApiResponse::ok($this->presenters->certificateTemplates->listing($this->list->execute(
            $request->principal,
            $state === '' ? null : $state,
            $format === '' ? null : $format,
            $request->queryInt('page', 1),
            $request->queryInt('per_page', 20),
            $request->queryString('search'),
            $request->queryString('category') ?: null,
            $request->queryString('renderer') ?: null,
        )));
    }
}
