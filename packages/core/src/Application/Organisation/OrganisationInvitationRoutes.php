<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\Handler\OrganisationInvitationsHandler;
use Shondalai\VidyaLMS\Core\Http\Route;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/** Register ONLY with a real transactional notification delivery bridge. */
final class OrganisationInvitationRoutes
{
    /** @return list<array{string, string, string}> */
    public static function definitions(): array
    {
        return [
            ['GET', '/manage/organisations/{id}/invitations', 'list'],
            ['POST', '/manage/organisations/{id}/invitations', 'issue'],
            ['POST', '/manage/organisations/{id}/invitations/{invitation_id}/revoke', 'revoke'],
            ['POST', '/me/organisation-invitations/claim', 'claim'],
            ['POST', '/me/organisation-invitations/accept', 'accept'],
        ];
    }

    public static function register(
        ApiKernel $api,
        ManageOrganisationInvitations $invitations,
        OrgScopeResolver $access,
        IdempotentExecution $idempotent,
        DatabasePort $database
    ): void {
        foreach (self::definitions() as [$method, $path, $operation]) {
            $api->register(new Route(
                $method,
                $path,
                new OrganisationInvitationsHandler($operation, $invitations, $access, $idempotent, $database)
            ));
        }
    }
}
