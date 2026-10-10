<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\Handler\OrganisationUnitsHandler;
use Shondalai\VidyaLMS\Core\Http\Route;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

final class OrganisationUnitRoutes
{
    /** @return list<array{string, string}> */
    public static function definitions(): array
    {
        return [['GET', '/manage/organisations/{id}/units'], ['POST', '/manage/organisations/{id}/units'],
            ['GET', '/manage/organisations/{id}/units/{unit_id}'],
            ['PATCH', '/manage/organisations/{id}/units/{unit_id}']];
    }

    public static function register(
        ApiKernel $api,
        ManageOrganisationUnits $units,
        OrgScopeResolver $access,
        IdempotentExecution $idempotent,
        DatabasePort $database
    ): void {
        foreach (self::definitions() as [$method, $path]) {
            $api->register(new Route($method, $path, new OrganisationUnitsHandler($units, $access, $idempotent, $database)));
        }
    }
}
