<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\AddCertificateTemplatePage;
use Shondalai\VidyaLMS\Core\Application\Credentials\CertificateTemplatePageView;
use Shondalai\VidyaLMS\Core\Application\Credentials\ListCertificateTemplatePages;
use Shondalai\VidyaLMS\Core\Application\Credentials\ReorderCertificateTemplatePages;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * GET  /manage/certificate-templates/{id}/pages
 * POST /manage/certificate-templates/{id}/pages
 * PUT  /manage/certificate-templates/{id}/pages/order
 *
 * The order is replaced as a whole list rather than patched a sequence at a
 * time, which is how every ordered collection on this contract is reordered:
 * a list is one write and one conflict, while N sequence patches are N races.
 */
final class CertificateTemplatePagesHandler implements HandlerInterface
{
    private const ROUTE_ADD = 'manage.certificate_template.pages.add';
    private const ROUTE_ORDER = 'manage.certificate_template.pages.order';

    public function __construct(
        private readonly ListCertificateTemplatePages $list,
        private readonly AddCertificateTemplatePage $add,
        private readonly ReorderCertificateTemplatePages $reorder,
        private readonly PresenterRegistry $presenters,
        private readonly CertificateTemplateWrite $write,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $templateId = $request->intPathParam('id');

        if ($request->method === 'POST') {
            return $this->write->run(
                $request,
                self::ROUTE_ADD . ':' . $templateId,
                fn (): ApiResponse => ApiResponse::created($this->presenters->certificateTemplates->pageMutation(
                    $this->add->execute($request->principal, $templateId, $request->body),
                )),
            );
        }

        if ($request->method === 'PUT') {
            return $this->write->run(
                $request,
                self::ROUTE_ORDER . ':' . $templateId,
                fn (): ApiResponse => ApiResponse::ok($this->presenters->certificateTemplates->pagesMutation(
                    $this->reorder->execute($request->principal, $templateId, $request->body),
                )),
            );
        }

        return ApiResponse::ok($this->pages($this->list->execute($request->principal, $templateId)));
    }

    /**
     * @param list<CertificateTemplatePageView> $pages
     *
     * @return array<string, mixed>
     */
    private function pages(array $pages): array
    {
        return [
            'items' => array_map(
                fn (CertificateTemplatePageView $page): array => $this->presenters->certificateTemplates->page($page),
                $pages,
            ),
        ];
    }
}
