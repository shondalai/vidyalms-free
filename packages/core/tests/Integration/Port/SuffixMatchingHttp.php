<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Port;

use Shondalai\VidyaLMS\Core\Port\Http\HttpPort;
use Shondalai\VidyaLMS\Core\Port\Http\HttpRequest;
use Shondalai\VidyaLMS\Core\Port\Http\HttpResponse;

/**
 * The mistake the HTTP port contract exists to catch, made on purpose.
 *
 * A port that admits every host is the extreme form of the suffix-matching
 * allowlist: `api.stripe.com` admitting `notapi.stripe.com`, which is a host
 * an attacker can register. Without a fixture that has the defect, the
 * contract's own refusal has never been seen to fire.
 */
final class SuffixMatchingHttp implements HttpPort
{
    public function request(HttpRequest $request): HttpResponse
    {
        return HttpResponse::of(200, 'this call should never have been made');
    }

    public function allows(string $host): bool
    {
        return true;
    }
}
