<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\Handler\OrganisationImportHandler;
use Shondalai\VidyaLMS\Core\Http\Route;
use Shondalai\VidyaLMS\Core\Port\Clock\ClockPort;

/**
 * The organisation import surface: six routes under the organisation's own
 * path, which is what makes the free build's "adds people by CSV" reachable.
 *
 * They sit under /manage/organisations/{id} rather than beside /import for two
 * reasons that are the same reason twice. OrgScopeMiddleware resolves and pins
 * the active organisation for exactly this prefix, and
 * OrganisationAccessLogMiddleware records every GET under it: a route placed
 * anywhere else would need both rules restated, and a restated rule is a rule
 * with two implementations.
 *
 * The log has a route of its own beside the job's, and serves the parsed notes
 * rather than a second copy of the job. A screen polling a running roster load
 * wants the log and nothing else, and a poll that carries the options map, the
 * totals and the whole job shape on every tick is a poll that costs more than
 * the import.
 */
final class OrganisationImportRoutes
{
    /** @return list<array{string, string, string}> */
    public static function definitions(): array
    {
        $base = '/manage/organisations/{id}/imports';

        return [
            ['POST', $base, 'start'],
            ['GET', $base, 'jobs'],
            ['GET', $base . '/{job_id}', 'job'],
            ['GET', $base . '/{job_id}/log', 'log'],
            ['GET', $base . '/{job_id}/problems', 'problems'],
            ['GET', $base . '/{job_id}/failed-rows', 'failed_rows'],
        ];
    }

    public static function register(
        ApiKernel $api,
        OrganisationImports $imports,
        IdempotentExecution $idempotent,
        ClockPort $clock,
    ): void {
        foreach (self::definitions() as [$method, $path, $operation]) {
            $api->register(new Route(
                $method,
                $path,
                new OrganisationImportHandler($operation, $imports, $idempotent, $clock),
            ));
        }
    }
}
