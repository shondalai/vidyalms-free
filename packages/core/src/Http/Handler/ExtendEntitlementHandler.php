<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Access\ExtendEntitlement;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/entitlements/{id}/extend
 */
final class ExtendEntitlementHandler implements HandlerInterface
{
    private const ROUTE = 'manage.entitlements.extend';

    public function __construct(
        private readonly ExtendEntitlement $extendEntitlement,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $entitlementId = $request->intPathParam('id');

        if (!\array_key_exists('expires_at', $request->body)) {
            throw ApiProblem::validation(['expires_at' => 'An expiry, or null for never, is required.']);
        }

        $expiresAt = Body::nullableString($request->body, 'expires_at');

        return $this->idempotent->run(
            $request,
            self::ROUTE,
            function () use ($request, $entitlementId, $expiresAt): ApiResponse {
                return ApiResponse::ok($this->presenters->access->accessGrant(
                    $this->extendEntitlement->execute($request->principal, $entitlementId, $expiresAt),
                ));
            },
        );
    }
}
