<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Notification;

use Shondalai\VidyaLMS\Core\Application\Notification\NotificationPreferenceCentre;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET and PUT /me/notification-preferences
 *
 * The caller's own rows and nobody else's: this route takes no user id.
 */
final class MyNotificationPreferencesHandler implements HandlerInterface
{
    public function __construct(
        private readonly NotificationPreferenceCentre $centre,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        if ($request->method === 'GET') {
            return ApiResponse::ok($this->centre->get($request->principal));
        }

        return $this->idempotent->run(
            $request,
            'notification.preferences.put',
            function () use ($request): ApiResponse {
                $rows = $request->body['rows'] ?? null;

                return ApiResponse::ok($this->centre->put(
                    $request->principal,
                    \is_array($rows) ? array_values($rows) : [],
                ));
            },
        );
    }
}
