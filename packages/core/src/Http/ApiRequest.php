<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http;

use Shondalai\VidyaLMS\Core\Application\Shared\Principal;

/**
 * The neutral request handed to core handlers. Adapters translate their
 * host's request (Joomla webservices, WordPress REST) into this shape and
 * attach the authenticated principal; the core never parses cookies, tokens
 * or nonces itself.
 *
 * Paths are relative to the /v1 mount: "/courses/42/curriculum".
 *
 * Almost every route reads $body, the decoded JSON object. Two do not, and
 * they are why rawBody and contentType are here. An RFC 5965 feedback report
 * arrives as message/feedback-report, a MIME document that is not JSON and has
 * no array form, and an RFC 8058 one-click POST arrives as
 * application/x-www-form-urlencoded carrying the single field
 * List-Unsubscribe=One-Click. An adapter that decoded every body as JSON
 * answered both with 400 before the core saw them.
 *
 * The adapter rule is a property of the transport and carries no route
 * knowledge: a body whose content type is JSON, or that arrives with no
 * content type at all, is decoded exactly as before and a malformed one is
 * still a 400; any other content type is carried through untouched with an
 * empty $body. A handler that wants the bytes asks for rawBody and is
 * responsible for its own parsing, which is where an anti-corruption mapper
 * belongs.
 *
 */
final class ApiRequest
{
    /**
     * The request headers an adapter forwards. It is an allowlist and not the
     * whole header set: a route that needs one names it here, so an adapter
     * loops over a core constant rather than holding route knowledge of its
     * own, and no cookie, authorization header or host header can reach a
     * handler by accident. Names are lowercased.
     *
     * `accept` is here because `GET /credentials/{uuid}` is served under
     * content negotiation and reads it. Left off the list it arrived empty on
     * both hosts, every request fell through to the first registered format,
     * and a wallet asking for a PNG was handed JSON-LD under a PNG request
     * with no error anywhere. It is safe to forward: it names a preference and
     * identifies nobody.
     */
    public const FORWARDED_HEADERS = ['x-vidyalms-feedback-secret', 'accept'];

    /**
     * The headers an adapter forwards for one path, given everything the
     * request carried.
     *
     * The rule lives here so all three adapters keep looping over the core
     * rather than holding route knowledge of their own, which is the property
     * the allowlist was written for and which a second per-adapter rule would
     * have thrown away.
     *
     * @param array<string, string> $headers every header the request carried, keyed by lowercased name
     *
     * @return array<string, string>
     */
    public static function headersFor(array $headers): array
    {
        $kept = [];

        foreach (self::FORWARDED_HEADERS as $name) {
            $value = $headers[$name] ?? '';

            if ($value !== '') {
                $kept[$name] = $value;
            }
        }

        return $kept;
    }

    /**
     * @param array<string, string>      $query
     * @param array<string, mixed>       $body
     * @param array<string, string>      $pathParams
     * @param ?string                    $rawBody     the untouched request body, when the adapter
     *                                                did not decode it
     * @param ?string                    $contentType the request's media type, parameters stripped
     * @param array<string, string>      $headers     the FORWARDED_HEADERS the request carried,
     *                                                keyed by lowercased name
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly Principal $principal,
        public readonly array $query = [],
        public readonly array $body = [],
        public readonly ?string $idempotencyKey = null,
        public readonly array $pathParams = [],
        public readonly ?string $rawBody = null,
        public readonly ?string $contentType = null,
        public readonly array $headers = [],
        /**
         * The caller's address, as the adapter validated it, or null when the
         * host could not establish one.
         *
         * It exists for the guest rate-limit bucket and nothing else. Without
         * it every anonymous caller shared one counter per path prefix, so
         * one client looping on the unsubscribe route locked the whole site
         * out of unsubscribing, which is the endpoint the law requires to
         * work. The core does not read it from a header: an address taken
         * from a header the adapter did not validate is worse than counting
         * coarsely, so each adapter supplies it from its own trusted source.
         */
        public readonly ?string $clientAddress = null,
    ) {
    }

    /**
     * @param array<string, string> $pathParams
     */
    public function withPathParams(array $pathParams): self
    {
        return new self(
            $this->method,
            $this->path,
            $this->principal,
            $this->query,
            $this->body,
            $this->idempotencyKey,
            $pathParams,
            $this->rawBody,
            $this->contentType,
            $this->headers,
            $this->clientAddress,
        );
    }

    /**
     * The same request under the kernel's canonical spelling of its path.
     *
     * It exists so {@see ApiKernel::canonicalPath()} can be applied once,
     * above every middleware, rather than each middleware re-deriving a
     * spelling and one of them getting it wrong.
     */
    public function withPath(string $path): self
    {
        return new self(
            $this->method,
            $path,
            $this->principal,
            $this->query,
            $this->body,
            $this->idempotencyKey,
            $this->pathParams,
            $this->rawBody,
            $this->contentType,
            $this->headers,
            $this->clientAddress,
        );
    }

    /**
     * A forwarded header, or null when the request did not carry it.
     */
    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;

        return $value === null || $value === '' ? null : $value;
    }

    public function pathParam(string $name): string
    {
        return $this->pathParams[$name]
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            ?? throw new \LogicException(sprintf('Path parameter "%s" is not present on this route.', $name));
    }

    public function intPathParam(string $name): int
    {
        $value = $this->pathParam($name);

        if (preg_match('/^[1-9][0-9]*\z/', $value) !== 1
            || filter_var($value, FILTER_VALIDATE_INT) === false
        ) {
            throw ApiProblem::notFound();
        }

        return (int) $value;
    }

    public function queryString(string $name, string $default = ''): string
    {
        return $this->query[$name] ?? $default;
    }

    public function queryInt(string $name, int $default): int
    {
        $raw = $this->query[$name] ?? null;

        if ($raw === null || preg_match('/^-?[0-9]+$/D', $raw) !== 1 || filter_var($raw, FILTER_VALIDATE_INT) === false) {
            return $default;
        }

        // Bound paging before downstream offset multiplication. Other integer
        // parameters (identifiers and timestamps) retain their full range.
        if (in_array($name, ['page', 'offset', 'startIndex'], true)
            && ((int) $raw < 0 || (int) $raw > 1000000)) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ApiProblem::validation([$name => 'The requested page is outside the supported range.']);
        }

        return (int) $raw;
    }

    /**
     * A flag in the query string, absent meaning false.
     *
     * The spellings a query string actually arrives in: a checkbox posts "1",
     * a hand-built request writes "true", and a client that sets the parameter
     * to nothing at all means it.
     */
    public function queryBool(string $name, bool $default = false): bool
    {
        $raw = $this->query[$name] ?? null;

        if ($raw === null) {
            return $default;
        }

        return \in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on', ''], true);
    }
}
