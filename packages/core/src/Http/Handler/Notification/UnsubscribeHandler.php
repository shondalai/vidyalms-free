<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Notification;

use Shondalai\VidyaLMS\Core\Application\Notification\Unsubscribe;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * GET and POST /notifications/unsubscribe/{token}
 *
 * The GET renders and acts on nothing, because mail security scanners follow
 * links and a GET that unsubscribed would turn a scanner into an opt-out.
 *
 * The POST is the one-click endpoint and the human form at once, and it is
 * one of only two mutations on this contract with no idempotency key: the
 * caller is a mail client that cannot be asked to mint one, and it needs
 * none, because setting a preference that is already set changes nothing and
 * the second call answers the same outcome.
 */
final class UnsubscribeHandler implements HandlerInterface
{
    public function __construct(private readonly Unsubscribe $unsubscribe)
    {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $token = $request->pathParam('token');

        if ($request->method === 'GET') {
            return ApiResponse::ok($this->unsubscribe->preview($token));
        }

        $channels = $request->body['channels'] ?? null;
        $selected = null;

        if (\is_array($channels)) {
            $selected = [];

            foreach ($channels as $channel) {
                if (\is_string($channel)) {
                    $selected[] = $channel;
                }
            }
        }

        return ApiResponse::ok($this->unsubscribe->execute($token, $selected));
    }
}
