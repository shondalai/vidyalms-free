<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\Handler\OrganisationManagementHandler;
use Shondalai\VidyaLMS\Core\Http\Route;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/** Explicit composition fragment; not registered until the schema/adapter gate passes. */
final class OrganisationRoutes
{
    /** @return list<array{string, string, string}> */
    public static function definitions(): array
    {
        return [
            ['GET', '/me/organisations', 'mine'],
            ['GET', '/me/organisations/{id}/learning', 'my_learning'],
            ['GET', '/manage/organisations', 'list'],
            ['POST', '/manage/organisations', 'create'],
            ['GET', '/manage/organisations/{id}', 'get'],
            ['PATCH', '/manage/organisations/{id}', 'update'],
            ['GET', '/manage/organisations/{id}/members', 'members'],
            ['POST', '/manage/organisations/{id}/members', 'add_member'],
            // The one read that names a person. It exists for two reasons the
            // review found together: amendment A8's invariant is that the list
            // and the detail read agree, and it was assertable only at the
            // store because the detail read had no route; and the access log's
            // subject anchor, its index and AdminAccessLogQuery::forSubject()
            // could never match a row, because no registered route named a
            // person and the middleware was pattern-matching a path shape the
            // product does not serve.
            ['GET', '/manage/organisations/{id}/members/{member_id}', 'member'],
            ['PATCH', '/manage/organisations/{id}/members/{member_id}', 'update_member'],
            ['GET', '/manage/organisations/{id}/events', 'events'],
            ['GET', '/manage/organisations/{id}/roles', 'roles'],
        ];
    }

    public static function register(
        ApiKernel $api,
        ManageOrganisations $organisations,
        ManageOrganisationMembers $members,
        OrgScopeResolver $access,
        IdempotentExecution $idempotent,
        DatabasePort $database,
        ?OrganisationLearningEvents $events = null,
        ?MyOrganisationLearning $learning = null,
    ): void {
        foreach (self::definitions() as [$method, $path, $operation]) {
            $api->register(new Route(
                $method,
                $path,
                new OrganisationManagementHandler($operation, $organisations, $members, $access, $idempotent, $database, $events, $learning)
            ));
        }
    }
}
