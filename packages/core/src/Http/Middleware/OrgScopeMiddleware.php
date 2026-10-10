<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Middleware;

use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Platform\Settings;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Middleware;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Organisation\OrganisationStore;
use Shondalai\VidyaLMS\Core\Port\Random\RandomPort;

/**
 * Resolves and pins the active organisation once per request.
 *
 * Core prerequisite 19. Without it the alternative was one edit per
 * organisation route for a rule that belongs to the whole surface, and the
 * request had no single place that knew which organisation it was about, so
 * the access log had nothing to record and a scoped read had nothing to
 * narrow by.
 *
 * **The path id stays the authority check it already is.** This middleware
 * never refuses. Every organisation route still calls the resolver itself and
 * still validates the id in its own path against the caller's own
 * org_memberships rows, which is what Auth0's guidance to relying parties
 * requires and what the shipped code already did correctly. What this adds is
 * the recording of the answer, so a second authorisation with a second error
 * vocabulary is not introduced beside the first.
 *
 * The order is the design's, minus the two steps whose sources do not exist
 * yet:
 *
 *  1. the organisation the path names, which on this surface is the explicit
 *     switch a person makes, and which is remembered when it changes;
 *  2. the remembered preference, validated against membership every time and
 *     never trusted as authority;
 *  3. the caller's single active membership, when they have exactly one;
 *  4. otherwise no organisation scope at all.
 *
 * A verified claimed domain is step 3 in the design and is absent here
 * because org_domains does not exist yet; a token claim is deliberately never
 * a step at all, because an org_id claim that has not been checked against
 * membership the application already knows is the entry point the other four
 * exist to close.
 *
 * The preference is a settings row keyed by the person's own id rather than a
 * column on a user table the core does not own. It is written only when the
 * active organisation actually changes, so an ordinary page load is a read.
 */
final class OrgScopeMiddleware implements Middleware
{
    /** The remembered preference, one row per person who has ever switched. */
    public const PREFERENCE_PREFIX = 'organisations.active_for_user.';

    /**
     * The salt under which a caller's address is hashed for the access log.
     *
     * A bare sha256 of an IPv4 address is a four-billion-entry rainbow table,
     * which is not a hash of personal data so much as a slow spelling of it.
     * The salt is minted once, from the same source the invitation tokens
     * use, and lives beside the log it protects.
     */
    public const IP_SALT_SETTING = 'admin_access_log.ip_salt';

    /** Paths this middleware reads at all. Every other route pays one str_starts_with. */
    public const MANAGE_PREFIX = '/manage/organisations';
    public const MINE_PATH = '/me/organisations';

    /**
     * The address salt, read once per process rather than once per request.
     *
     * It is a site secret that is minted once and never rotates, so re-reading
     * it on every organisation request would be a settings query bought for
     * nothing. A rotation, if one is ever wanted, is a new setting name rather
     * than a new value under this one, because every hash already written
     * would stop comparing.
     */
    private ?string $salt = null;

    public function __construct(
        private readonly ActiveOrganisation $active,
        private readonly OrgScopeResolver $resolver,
        private readonly OrganisationStore $store,
        private readonly Settings $settings,
        private readonly RandomPort $random,
    ) {
    }

    public function process(ApiRequest $request, callable $next): ApiResponse
    {
        if (!$this->covers($request->path) || $request->principal->isGuest()) {
            return $next($request);
        }

        $requested = self::organisationInPath($request->path);
        [$scope, $basis] = $this->resolve($request, $requested);
        $this->active->pin($scope, $basis, $requested, $this->random->uuid(), $this->ipHash($request));

        try {
            return $next($request);
        } finally {
            $this->active->release();
        }
    }

    /**
     * The id in "/manage/organisations/{id}", or null when the path names none.
     *
     * It is read from the path rather than from the matched route parameters
     * because middleware runs before dispatch: no route has matched yet, which
     * is also why this may never be the authority for anything.
     */
    public static function organisationInPath(string $path): ?int
    {
        if (preg_match('#^/manage/organisations/([^/]+)(?:/|\z)#', $path, $found) !== 1) {
            return null;
        }

        $id = rawurldecode($found[1]);
        if (preg_match('/^[1-9][0-9]*\z/', $id) !== 1
            || filter_var($id, FILTER_VALIDATE_INT) === false
        ) {
            return null;
        }

        return (int) $id;
    }

    private function covers(string $path): bool
    {
        return str_starts_with($path, self::MANAGE_PREFIX) || str_starts_with($path, self::MINE_PATH);
    }

    /**
     * @return array{0: ?OrgScope, 1: string}
     */
    private function resolve(ApiRequest $request, ?int $requested): array
    {
        if ($requested !== null) {
            $scope = $this->tryScope($request, $requested);

            if ($scope !== null) {
                $this->remember($request->principal->userId, $requested);

                return [$scope, ActiveOrganisation::SOURCE_PATH];
            }

            // A path naming an organisation the caller cannot reach is not an
            // occasion to fall back to a different one. The route is about to
            // refuse, and the log wants the organisation that was asked for.
            return [null, ActiveOrganisation::SOURCE_NONE];
        }

        $remembered = $this->settings->getInt(self::PREFERENCE_PREFIX . $request->principal->userId, 0);

        if ($remembered > 0) {
            $scope = $this->tryScope($request, $remembered);

            if ($scope !== null) {
                return [$scope, ActiveOrganisation::SOURCE_REMEMBERED];
            }
        }

        $sole = $this->soleMembership($request->principal->userId);

        if ($sole !== null) {
            $scope = $this->tryScope($request, $sole);

            if ($scope !== null) {
                return [$scope, ActiveOrganisation::SOURCE_SOLE_MEMBERSHIP];
            }
        }

        return [null, ActiveOrganisation::SOURCE_NONE];
    }

    /**
     * The resolver's own answer, with its refusal swallowed.
     *
     * Every refusal from the resolver is one indistinguishable
     * EntityNotFoundException by design, so there is nothing here to
     * distinguish and nothing to report: an unreachable organisation simply
     * does not become the active one, and the route that cares refuses in its
     * own words a moment later.
     */
    private function tryScope(ApiRequest $request, int $organisationId): ?OrgScope
    {
        try {
            return $this->resolver->require($request->principal, $organisationId);
        } catch (\Throwable) {
            return null;
        }
    }

    private function soleMembership(int $userId): ?int
    {
        $page = $this->store->ownOrganisations($userId, 1, 2);

        if ($page['total'] !== 1 || $page['items'] === []) {
            return null;
        }

        return (int) $page['items'][0]['id'];
    }

    private function remember(int $userId, int $organisationId): void
    {
        $name = self::PREFERENCE_PREFIX . $userId;

        if ($this->settings->getInt($name, 0) === $organisationId) {
            return;
        }

        $this->settings->set($name, $organisationId);
    }

    private function ipHash(ApiRequest $request): ?string
    {
        $address = $request->clientAddress;

        if ($address === null || $address === '') {
            return null;
        }

        return hash('sha256', $this->salt() . "\0" . $address);
    }

    private function salt(): string
    {
        if ($this->salt !== null) {
            return $this->salt;
        }

        $stored = $this->settings->getString(self::IP_SALT_SETTING, '');

        if ($stored === '') {
            $stored = $this->random->uuid();
            $this->settings->set(self::IP_SALT_SETTING, $stored);
        }

        $this->salt = $stored;

        return $stored;
    }
}
