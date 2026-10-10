<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Middleware;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\RecordsOrganisationAccess;

/**
 * The active organisation of the request being handled, pinned once.
 *
 * The design's sentence is that the active organisation is a property of the
 * request. ApiRequest is a readonly value object every adapter constructs
 * positionally, so the property lives here instead: one object, pinned by
 * {@see OrgScopeMiddleware} at the top of the chain and released in the same
 * finally, which is the only mutable per-request state the core holds. PHP
 * serves one request per process, so "pinned once per request" is exactly
 * what a single instance means; {@see release()} exists so a long-running
 * runner that handles two requests in one process cannot leak the first
 * caller's tenant into the second.
 *
 * It is deliberately not an authority. The path id validated against the
 * caller's own membership rows is still what decides every organisation
 * route, exactly as before, and this object answers a different question:
 * which organisation the request turned out to be about, so that the access
 * log can record one and a later scoped read has something to narrow by. A
 * middleware that refused would be a second authorisation with a different
 * error vocabulary, which is how a refusal starts disagreeing with itself.
 *
 * It also **listens**. The middleware resolves before dispatch and can only
 * ask for the "read" right, while a route resolves the right it needs, so the
 * two disagreed for any role permitting something other than reading. The
 * resolver now publishes every scope it mints here through
 * {@see RecordsOrganisationAccess}, and {@see resolvedScope()} prefers the
 * pinned one and falls back to what the route actually used, which is the
 * scope an auditor is asking about.
 */
final class ActiveOrganisation implements RecordsOrganisationAccess
{
    /**
     * How the active organisation was found, which is not the same question as
     * OrgScope::basis and must not share its name.
     *
     * OrgScope::basis is the authority a scope was reached under, is a closed
     * five-value vocabulary, and is a digest input. This is the step of the
     * middleware's own fixed order that answered, and nothing stores it yet:
     * there is no column beside scope_digest for it, and the wave that adds
     * the organisation-scoped report artefact is the wave that gains one. It
     * is kept and renamed rather than deleted because the order it records is
     * the design's, and a middleware that could not say which step answered
     * would be untestable.
     */
    public const SOURCE_NONE = 'none';
    public const SOURCE_REMEMBERED = 'remembered';
    public const SOURCE_PATH = 'path';
    public const SOURCE_SOLE_MEMBERSHIP = 'sole_membership';

    private ?OrgScope $scope = null;
    private ?OrgScope $observed = null;
    private ?int $subjectUserId = null;
    private ?int $requestedId = null;
    private string $source = self::SOURCE_NONE;
    private string $requestRef = '';
    private ?string $ipHash = null;

    public function pin(?OrgScope $scope, string $source, ?int $requestedId, string $requestRef, ?string $ipHash): void
    {
        $this->scope = $scope;
        $this->source = $scope === null ? self::SOURCE_NONE : $source;
        $this->requestedId = $requestedId;
        $this->requestRef = $requestRef;
        $this->ipHash = $ipHash;
    }

    public function release(): void
    {
        $this->scope = null;
        $this->observed = null;
        $this->subjectUserId = null;
        $this->requestedId = null;
        $this->source = self::SOURCE_NONE;
        $this->requestRef = '';
        $this->ipHash = null;
    }

    /**
     * A scope a route minted, kept only when it is about the organisation the
     * request named.
     *
     * The guard matters: a request under /manage/organisations/1 may reach a
     * use case that resolves a different tenant for its own reasons, and a
     * record that took the last scope it saw would anchor the read on an
     * organisation the caller never asked about.
     */
    public function noteScope(OrgScope $scope): void
    {
        if ($this->observed !== null) {
            return;
        }

        if ($this->requestedId !== null && $scope->organisationId !== $this->requestedId) {
            return;
        }

        $this->observed = $scope;
    }

    public function noteSubject(int $userId): void
    {
        if ($userId > 0 && $this->subjectUserId === null) {
            $this->subjectUserId = $userId;
        }
    }

    public function scope(): ?OrgScope
    {
        return $this->scope;
    }

    /** The pinned scope, or the one the route resolved for the right it needed. */
    public function resolvedScope(): ?OrgScope
    {
        return $this->scope ?? $this->observed;
    }

    /** The person this request turned out to be about, when a route resolved one. */
    public function subjectUserId(): ?int
    {
        return $this->subjectUserId;
    }

    /** The organisation the request named, whether or not the caller reached it. */
    public function requestedId(): ?int
    {
        return $this->requestedId;
    }

    /** Which step of the middleware's fixed order answered; see the constants. */
    public function source(): string
    {
        return $this->source;
    }

    public function requestRef(): string
    {
        return $this->requestRef;
    }

    public function ipHash(): ?string
    {
        return $this->ipHash;
    }
}
