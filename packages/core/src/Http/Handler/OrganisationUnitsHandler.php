<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationUnits;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

final class OrganisationUnitsHandler implements HandlerInterface
{
    public function __construct(
        private readonly ManageOrganisationUnits $units,
        private readonly OrgScopeResolver $access,
        private readonly IdempotentExecution $idempotent,
        private readonly DatabasePort $database
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $orgId = $request->intPathParam('id');
        if ($request->method === 'GET') {
            if (isset($request->pathParams['unit_id'])) {
                return ApiResponse::ok($this->units->get($request->principal, $orgId, $request->intPathParam('unit_id')));
            }
            return ApiResponse::ok($this->units->list(
                $request->principal,
                $orgId,
                $request->queryInt('page', 1),
                $request->queryInt('per_page', 25),
                $request->queryString('search')
            ));
        }
        $scope = $this->access->require($request->principal, $orgId, 'people');
        if ($scope->unitId !== null) {
            throw new \Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException(
                'Unit structure is managed by organisation administrators.'
            );
        }

        return $this->idempotent->runAtomic(
            $request,
            'org.units.' . hash('sha256', $request->method . ':' . $request->path),
            $this->database,
            fn (): ApiResponse => ApiResponse::ok($this->units->save(
                $request->principal,
                $orgId,
                isset($request->pathParams['unit_id']) ? $request->intPathParam('unit_id') : null,
                $request->body
            )),
            ['body' => $request->body]
        );
    }
}
