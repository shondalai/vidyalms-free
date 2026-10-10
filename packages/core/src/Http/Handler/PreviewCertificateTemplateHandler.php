<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\PreviewCertificateTemplate;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/certificate-templates/{id}/preview
 *
 * A POST because the body carries who to resolve tokens against, and nothing
 * is written: no certificate row, no code, no status list index, no artefact
 * in storage. There is no idempotency claim for the same reason, because a
 * replay of a write that wrote nothing has nothing to protect.
 */
final class PreviewCertificateTemplateHandler implements HandlerInterface
{
    public function __construct(
        private readonly PreviewCertificateTemplate $preview,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return ApiResponse::ok($this->presenters->certificateTemplates->preview(
            $this->preview->execute($request->principal, $request->intPathParam('id'), $request->body),
        ));
    }
}
