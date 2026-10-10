<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Access\AccessGrantView;
use Shondalai\VidyaLMS\Core\Application\Access\RevokeEntitlement;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\PresenterRegistry;

/**
 * POST /manage/entitlements/{id}/revoke
 */
final class RevokeEntitlementHandler implements HandlerInterface
{
    private const ROUTE = 'manage.entitlements.revoke';

    public function __construct(
        private readonly RevokeEntitlement $revokeEntitlement,
        private readonly AccessGrantView $view,
        private readonly IdempotentExecution $idempotent,
        private readonly PresenterRegistry $presenters,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $entitlementId = $request->intPathParam('id');

        return $this->idempotent->run($request, self::ROUTE, function () use ($request, $entitlementId): ApiResponse {
            $entitlement = $this->revokeEntitlement->execute($request->principal, $entitlementId);

            return ApiResponse::ok($this->presenters->access->accessGrant($this->view->of($entitlement)));
        });
    }
}
