<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Organisation;

use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\Handler\OrganisationLearningHandler;
use Shondalai\VidyaLMS\Core\Http\Route;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

final class OrganisationLearningRoutes
{
    /** @return list<array{string, string, string}> */
    public static function definitions(): array
    {
        $base = '/manage/organisations/{id}';

        return [
            ['GET', $base . '/summary', 'summary'],
            ['GET', $base . '/cleanup-jobs', 'cleanup_jobs'],
            ['POST', $base . '/cleanup-jobs/{job_id}/retry', 'retry_cleanup'],
            ['GET', $base . '/releases', 'releases'],
            ['POST', $base . '/releases', 'release_course'],
            ['GET', $base . '/learning', 'learning'],
            ['POST', $base . '/learning/grant', 'grant'],
            ['POST', $base . '/learning/{entitlement_id}/revoke', 'revoke'],
            ['GET', $base . '/seat-pools', 'pools'],
            ['POST', $base . '/seat-pools', 'create_pool'],
            ['PATCH', $base . '/seat-pools/{pool_id}', 'update_pool'],
            ['GET', $base . '/seat-pools/{pool_id}', 'pool'],
            ['GET', $base . '/seat-pools/{pool_id}/assignments', 'assignments'],
            ['POST', $base . '/seat-pools/{pool_id}/assignments', 'assign'],
            ['POST', $base . '/seat-assignments/{assignment_id}/release', 'release_seat'],
            // The acquiring half and the releasing half in one call, because
            // Entra documents that removing a licence first leaves the person
            // unlicensed while processing catches up, and two routes cannot
            // share a transaction.
            ['POST', $base . '/seat-assignments/{assignment_id}/transfer', 'transfer_seat'],
            ['GET', $base . '/seat-pools/{pool_id}/ledger', 'ledger'],
            // The two derivations at a chosen instant. Registered after the
            // ledger for no reason but tidiness: neither path is a prefix of
            // the other, so first-registration-wins does not bite here.
            ['GET', $base . '/seat-pools/{pool_id}/reconciliation', 'reconciliation'],
        ];
    }

    public static function register(
        ApiKernel $api,
        ManageOrganisationLearning $learning,
        ManageOrganisationSeats $seats,
        OrgScopeResolver $access,
        IdempotentExecution $idempotent,
        DatabasePort $database,
        OrganisationLearningEvents $events,
        /** Defaulted so a caller built before the transfer route still registers every other route. */
        ?TransferSeat $transfer = null,
    ): void {
        foreach (self::definitions() as [$method, $path, $operation]) {
            if ($operation === 'transfer_seat' && $transfer === null) {
                continue;
            }

            $api->register(new Route(
                $method,
                $path,
                new OrganisationLearningHandler(
                    $operation,
                    $learning,
                    $seats,
                    $access,
                    $idempotent,
                    $database,
                    $events,
                    $transfer
                )
            ));
        }
    }
}
