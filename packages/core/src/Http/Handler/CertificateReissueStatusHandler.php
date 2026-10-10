<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\GetCertificateReissueStatus;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

final class CertificateReissueStatusHandler implements HandlerInterface
{
    public function __construct(private readonly GetCertificateReissueStatus $status)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $revision = $request->queryInt('revision', 0);
        if (isset($request->query['revision']) && ($revision < 1 || $revision > 2147483646)) {
            throw ValidationException::forField('revision', 'Provide the run publication revision.');
        }
        return ApiResponse::ok($this->status->execute($request->principal, $request->intPathParam('id'), $revision ?: null))
            ->withHeaders(['Cache-Control' => 'private, no-store']);
    }
}
