<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\AddCertificateTemplateElement;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplateElementView;
use Shondalai\VidyaLMS\Core\Application\Credentials\ListCertificateTemplateElements;
use Shondalai\VidyaLMS\Core\Application\Credentials\ReorderCertificateTemplateElements;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET  /manage/certificate-template-pages/{id}/elements
 * POST /manage/certificate-template-pages/{id}/elements
 * PUT  /manage/certificate-template-pages/{id}/elements/order
 *
 * Sequence is draw order, so the reorder is what puts a border behind a name
 * rather than over it.
 */
final class CertificateTemplateElementsHandler implements HandlerInterface
{
    private const ROUTE_ADD = 'manage.certificate_template_page.elements.add';
    private const ROUTE_ORDER = 'manage.certificate_template_page.elements.order';

    public function __construct(
        private readonly ListCertificateTemplateElements $list,
        private readonly AddCertificateTemplateElement $add,
        private readonly ReorderCertificateTemplateElements $reorder,
        private readonly PresenterRegistry $presenters,
        private readonly CertificateTemplateWrite $write,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $pageId = $request->intPathParam('id');

        if ($request->method === 'POST') {
            return $this->write->run(
                $request,
                self::ROUTE_ADD . ':' . $pageId,
                fn (): ApiResponse => ApiResponse::created($this->presenters->certificateTemplates->elementMutation(
                    $this->add->execute($request->principal, $pageId, $request->body),
                )),
            );
        }

        if ($request->method === 'PUT') {
            return $this->write->run(
                $request,
                self::ROUTE_ORDER . ':' . $pageId,
                fn (): ApiResponse => ApiResponse::ok($this->presenters->certificateTemplates->elementsMutation(
                    $this->reorder->execute($request->principal, $pageId, $request->body),
                )),
            );
        }

        return ApiResponse::ok($this->elements($this->list->execute($request->principal, $pageId)));
    }

    /**
     * @param list<CertificateTemplateElementView> $elements
     *
     * @return array<string, mixed>
     */
    private function elements(array $elements): array
    {
        return [
            'items' => array_map(
                fn (CertificateTemplateElementView $element): array
                    => $this->presenters->certificateTemplates->element($element),
                $elements,
            ),
        ];
    }
}
