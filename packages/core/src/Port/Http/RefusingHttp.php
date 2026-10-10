<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Http;

/**
 * Default outbound transport: integrations receive an explicit refusal.
 */
final class RefusingHttp implements HttpPort
{
    public function request(HttpRequest $request): HttpResponse
    {
        return HttpResponse::refused(HttpResponse::REFUSED_NO_IMPLEMENTATION);
    }

    public function allows(string $host): bool
    {
        return false;
    }
}
