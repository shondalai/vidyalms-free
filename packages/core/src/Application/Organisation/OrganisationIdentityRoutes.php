<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\Handler\OrganisationIdentityHandler;
use Shondalai\VidyaLMS\Core\Http\Route;

/**
 * The organisation identity surface: connections, the links they produce,
 * domain claims and join rules.
 *
 * They sit under /manage/organisations/{id} for the two reasons that are the
 * same reason twice. OrgScopeMiddleware resolves and pins the active
 * organisation for exactly this prefix, and the access-log middleware records
 * every GET under it: a route placed anywhere else would need both rules
 * restated, and a restated rule is a rule with two implementations.
 *
 * Thirteen routes and two of them are different from the other eleven.
 * POST .../connections/{connection_id}/decision is the site-scoped
 * transition: verifying an issuer claim, marking a connection authoritative
 * and enabling account creation are the three decisions an organisation may
 * not make about itself, and they are one route rather than three so the
 * refusal that guards them is written once.
 */
final class OrganisationIdentityRoutes
{
    /** @return list<array{string, string, string}> */
    public static function definitions(): array
    {
        $base = '/manage/organisations/{id}';

        return [
            ['GET', $base . '/connections', 'connections'],
            ['POST', $base . '/connections', 'create_connection'],
            ['PATCH', $base . '/connections/{connection_id}', 'update_connection'],
            ['POST', $base . '/connections/{connection_id}/decision', 'decide_connection'],
            // Site scoped like the decision above it: delegation borrows another
            // organisation's proof, so an organisation may not grant itself one.
            ['POST', $base . '/connections/{connection_id}/delegation', 'delegate_connection'],
            ['GET', $base . '/identity-links', 'links'],
            ['POST', $base . '/identity-links/{link_id}/resolution', 'resolve_link'],
            ['GET', $base . '/domains', 'domains'],
            ['POST', $base . '/domains', 'claim_domain'],
            ['DELETE', $base . '/domains/{domain_id}', 'revoke_domain'],
            ['GET', $base . '/join-rules', 'join_rules'],
            ['POST', $base . '/join-rules', 'add_join_rule'],
            ['DELETE', $base . '/join-rules/{rule_id}', 'retire_join_rule'],
        ];
    }

    public static function register(
        ApiKernel $api,
        ManageOrganisationConnections $connections,
        ManageOrganisationJoining $joining,
    ): void {
        foreach (self::definitions() as [$method, $path, $operation]) {
            $api->register(new Route(
                $method,
                $path,
                new OrganisationIdentityHandler($operation, $connections, $joining),
            ));
        }
    }
}
