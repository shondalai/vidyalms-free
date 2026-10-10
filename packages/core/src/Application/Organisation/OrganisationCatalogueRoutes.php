<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\Handler\OrganisationCatalogueHandler;
use Shondalai\VidyaLMS\Core\Http\Route;

/**
 * The organisation catalogue surface: what an organisation may grant from.
 *
 * Under `/manage/organisations/{id}` for the two reasons every other
 * organisation route is: `OrgScopeMiddleware` resolves and pins the active
 * organisation for exactly this prefix, and the access-log middleware records
 * every GET under it.
 *
 * The two collection reads sit at fixed sub-paths rather than at
 * `/catalogue/{entry_id}`, and there is deliberately no GET on the entry
 * itself: `courses` and `sources` are words an entry id could never be, but
 * registering a GET on the id as well would put a numeric path and two literal
 * ones in one position and make correctness depend on registration order.
 */
final class OrganisationCatalogueRoutes
{
    /** @return list<array{string, string, string}> */
    public static function definitions(): array
    {
        $base = '/manage/organisations/{id}';

        return [
            ['GET', $base . '/catalogue', 'entries'],
            ['POST', $base . '/catalogue', 'add'],
            ['GET', $base . '/catalogue/courses', 'courses'],
            ['GET', $base . '/catalogue/sources', 'sources'],
            ['PATCH', $base . '/catalogue/{entry_id}', 'update'],
            ['DELETE', $base . '/catalogue/{entry_id}', 'retire'],
        ];
    }

    public static function register(ApiKernel $api, ManageOrganisationCatalogue $catalogue): void
    {
        foreach (self::definitions() as [$method, $path, $operation]) {
            $api->register(new Route($method, $path, new OrganisationCatalogueHandler($operation, $catalogue)));
        }
    }
}
