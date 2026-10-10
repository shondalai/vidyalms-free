<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Notification;

use Shondalai\VidyaLMS\Core\Application\Notification\ManageProviderSettings;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlProviderSettingsRepository;

/**
 * GET /manage/providers/{registry_key} and
 * PUT /manage/providers/{registry_key}/{provider_key}/settings
 */
final class ProviderSettingsHandler implements HandlerInterface
{
    public function __construct(
        private readonly ManageProviderSettings $settings,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $registryKey = $request->pathParam('registry_key');

        if ($request->method === 'GET') {
            return ApiResponse::ok($this->settings->list(
                $request->principal,
                $registryKey,
                $request->queryString('scope_type', SqlProviderSettingsRepository::SCOPE_SITE),
                $request->queryInt('scope_id', 0),
            ));
        }

        return $this->idempotent->run(
            $request,
            'provider.settings.save',
            function () use ($request, $registryKey): ApiResponse {
                $params = $request->body['params'] ?? null;
                $scopeType = $request->body['scope_type'] ?? SqlProviderSettingsRepository::SCOPE_SITE;
                $scopeId = $request->body['scope_id'] ?? 0;
                // Both absent means both untouched, which is what makes the
                // reference write-only usable: a form that never reads one
                // must not blank one by being saved.
                $secretRef = $request->body['secret_ref'] ?? null;
                $enabled = $request->body['enabled'] ?? null;

                return ApiResponse::ok($this->settings->save(
                    $request->principal,
                    $registryKey,
                    $request->pathParam('provider_key'),
                    \is_string($scopeType) ? $scopeType : SqlProviderSettingsRepository::SCOPE_SITE,
                    \is_numeric($scopeId) ? (int) $scopeId : 0,
                    \is_array($params) ? $params : [],
                    \is_string($secretRef) && $secretRef !== '' ? $secretRef : null,
                    \is_bool($enabled) ? $enabled : null,
                ));
            },
        );
    }
}
