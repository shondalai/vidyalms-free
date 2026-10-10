<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http;

/**
 * A cross-cutting concern wrapped around every route of the kernel.
 *
 * A licence check, an audit trail, a rate limit or a per-request cache is one
 * thing that has to happen on every endpoint, and without this seam adding one
 * meant editing all seventy-one handlers by hand. A middleware receives the
 * request and the next step; calling $next runs the rest of the chain and the
 * handler, and not calling it answers on its own.
 *
 * Middleware runs in registration order, outermost first, and a package that
 * registers one from onVidyaLmsKernelReady changes no free-build byte.
 */
interface Middleware
{
    /**
     * @param callable(ApiRequest): ApiResponse $next
     */
    public function process(ApiRequest $request, callable $next): ApiResponse;
}
