<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\Handler\OrganisationComplianceHandler;
use Shondalai\VidyaLMS\Core\Http\Route;

/**
 * The organisation compliance surface: requirements, assignments, cycles and
 * exceptions.
 *
 * Under `/manage/organisations/{id}` like every other organisation route, so
 * the scope middleware pins the tenant and the access log records every GET.
 *
 * Seven routes and only three of them write. The compliance seam shipped with
 * four tables, two state machines and a daily sweep and no writer at all, so
 * these three are what make the other four readable: without them the sweep
 * checks nothing on every site in the world and no rule package can be
 * exercised even after it is installed.
 */
final class OrganisationComplianceRoutes
{
    /** @return list<array{string, string, string}> */
    public static function definitions(): array
    {
        $base = '/manage/organisations/{id}/compliance';

        return [
            ['GET', $base . '/requirements', 'requirements'],
            ['POST', $base . '/requirements', 'create'],
            ['PATCH', $base . '/requirements/{requirement_id}', 'update'],
            ['POST', $base . '/requirements/{requirement_id}/assignments', 'assign'],
            ['GET', $base . '/assignments', 'assignments'],
            ['GET', $base . '/assignments/{assignment_id}/cycles', 'cycles'],
            ['GET', $base . '/exceptions', 'exceptions'],
        ];
    }

    public static function register(ApiKernel $api, ManageOrganisationCompliance $compliance): void
    {
        foreach (self::definitions() as [$method, $path, $operation]) {
            $api->register(new Route($method, $path, new OrganisationComplianceHandler($operation, $compliance)));
        }
    }
}
