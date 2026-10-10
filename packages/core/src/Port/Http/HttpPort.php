<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Http;

/**
 * Outbound transport for AI, notifications and other external integrations.
 * Adapters enforce TLS, host restrictions and request timeouts. The default
 * implementation refuses calls when the host has no configured transport.
 */
interface HttpPort
{
    /**
     * Makes one call, or says why it did not.
     *
     * It never throws for a remote failure or a refusal: both are answers, and
     * an exception out of a reconciliation pass stops the pass rather than
     * recording one failed call.
     */
    public function request(HttpRequest $request): HttpResponse;

    /**
     * Whether this implementation would make a call to that host at all.
     *
     * Lets setup screens report whether an integration host is permitted.
     */
    public function allows(string $host): bool;
}
