<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Middleware;

use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Middleware;

/**
 * Keeps a page that discloses a person out of search indexes.
 *
 * It is core middleware rather than a header each adapter remembers to set,
 * because the same security control written once per adapter is the same
 * control written differently twice. The verification page, the credential
 * routes and the unsubscribe pair carry it: a name against a completion is
 * personal data, and an unsubscribe landing page indexed by a crawler is an
 * invitation to walk the token space.
 */
final class NoIndexMiddleware implements Middleware
{
    public const HEADER = 'X-Robots-Tag';
    public const VALUE = 'noindex, nofollow, noarchive';

    /** @var list<string> */
    private readonly array $prefixes;

    /**
     * @param list<string> $prefixes path prefixes this applies to
     */
    public function __construct(array $prefixes)
    {
        $this->prefixes = $prefixes;
    }

    public function process(ApiRequest $request, callable $next): ApiResponse
    {
        $response = $next($request);

        if (!$this->covers($request->path)) {
            return $response;
        }

        // withHeaders() rather than a rebuilt ApiResponse: the constructor
        // takes the bytes of a binary response as a separate argument, and a
        // three-argument rebuild dropped them. The credential routes are one
        // of the prefixes this covers, so the baked PNG reached both adapters
        // as an ordinary document.
        return $response->withHeaders([self::HEADER => self::VALUE]);
    }

    private function covers(string $path): bool
    {
        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
