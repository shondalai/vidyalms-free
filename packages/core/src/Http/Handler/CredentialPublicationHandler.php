<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\CredentialNamePublication;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/** The authenticated holder's name preference applies to certificates and badges. */
final class CredentialPublicationHandler implements HandlerInterface
{
    public function __construct(private readonly Settings $settings, private readonly AccessGuard $guard)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $this->guard->requireAuthenticated($request->principal);
        $key = CredentialNamePublication::holderSetting($request->principal->userId);
        if ($request->method === 'PUT') {
            $publish = $request->body['publish_learner_name'] ?? null;
            if (!\is_bool($publish) || array_keys($request->body) !== ['publish_learner_name']) {
                throw ApiProblem::badRequest('publish_learner_name must be a boolean.');
            }
            $this->settings->set($key, $publish);
        }

        return ApiResponse::ok([
            'publish_learner_name' => $this->settings->getBool($key, true),
            'effective_publish_learner_name' => CredentialNamePublication::allowed($this->settings, $request->principal->userId),
        ]);
    }
}
