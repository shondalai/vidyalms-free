<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Middleware;

use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Middleware;

/**
 * The four rules a page about one named person carries, published once.
 *
 * They were written in prose and in code twice: both adapters reimplement the
 * same list for the AI activity view (forbid the host page cache, answer
 * `no-store`, send no referrer, stay out of search indexes), and the AI routes
 * themselves set none of them while the credential handlers next door set the
 * fourth by hand. A third host reinvents all four, which is exactly the shape
 * of failure `NoIndexMiddleware` already exists to prevent for one of them.
 *
 * So the rule is a prefix list and a middleware, and an adapter reads it
 * rather than inferring it. `Cache-Control: no-store` is the one that matters
 * most and is the easiest to forget: an activity view answering from a shared
 * cache hands one learner another learner's record.
 */
final class PersonalPageMiddleware implements Middleware
{
    /**
     * The routes that answer about one named person and nobody else.
     *
     * `/me/` is the whole family by construction: the subject id comes from
     * the session principal and never from a parameter, which is what makes
     * these pages personal in the first place.
     *
     * @var list<string>
     */
    public const PREFIXES = ['/me/', '/certificates/', '/credentials/', '/badges/'];

    /** @var array<string, string> */
    public const HEADERS = [
        'Cache-Control' => 'private, no-store',
        'Pragma' => 'no-cache',
        'Referrer-Policy' => 'no-referrer',
        NoIndexMiddleware::HEADER => NoIndexMiddleware::VALUE,
    ];

    /** @var list<string> */
    private readonly array $prefixes;

    /**
     * @param list<string> $prefixes
     */
    public function __construct(array $prefixes = self::PREFIXES)
    {
        $this->prefixes = $prefixes;
    }

    public function process(ApiRequest $request, callable $next): ApiResponse
    {
        $response = $next($request);

        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($request->path, $prefix)) {
                // withHeaders() rather than a rebuild, for the reason the
                // no-index middleware records: a three-argument rebuild drops
                // the bytes of a binary response.
                return $response->withHeaders(self::HEADERS);
            }
        }

        return $response;
    }
}
