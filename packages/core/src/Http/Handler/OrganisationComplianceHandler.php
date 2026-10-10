<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationCompliance;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * The seven organisation compliance routes, one handler.
 *
 * Transport only. The use case owns the tenancy check, the release rule, the
 * registry misses and the exception vocabulary.
 */
final class OrganisationComplianceHandler implements HandlerInterface
{
    public function __construct(
        private readonly string $operation,
        private readonly ManageOrganisationCompliance $compliance,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $orgId = $request->intPathParam('id');
        $actor = $request->principal;

        return match ($this->operation) {
            'requirements' => ApiResponse::ok(['items' => $this->compliance->requirements($actor, $orgId)]),
            'create' => ApiResponse::created($this->compliance->create($actor, $orgId, $request->body)),
            'update' => ApiResponse::ok($this->compliance->update(
                $actor,
                $orgId,
                $request->intPathParam('requirement_id'),
                $request->body,
            )),
            'assign' => ApiResponse::ok($this->compliance->assign(
                $actor,
                $orgId,
                $request->intPathParam('requirement_id'),
                $request->body,
            )),
            'assignments' => ApiResponse::ok($this->compliance->assignments(
                $actor,
                $orgId,
                $request->queryInt('page', 1),
                $request->queryInt('per_page', 25),
                $this->optionalId($request, 'requirement_id'),
                $this->optionalId($request, 'user_id'),
                $request->queryString('compliance_status', ''),
            )),
            'cycles' => ApiResponse::ok(['items' => $this->compliance->cycles(
                $actor,
                $orgId,
                $request->intPathParam('assignment_id'),
            )]),
            'exceptions' => ApiResponse::ok($this->compliance->exceptions(
                $actor,
                $orgId,
                $request->queryInt('page', 1),
                $request->queryInt('per_page', 25),
                $this->optionalId($request, 'requirement_id'),
                $request->queryString('kind', ''),
            )),
            default => throw new \LogicException('Unknown organisation compliance operation.'),
        };
    }

    /**
     * An absent filter is null rather than zero.
     *
     * A zero would be a predicate matching nothing, and a caller who omitted
     * the parameter meant "every row" rather than "the rows whose id is zero".
     */
    private function optionalId(ApiRequest $request, string $name): ?int
    {
        $value = $request->queryInt($name, 0);

        return $value > 0 ? $value : null;
    }
}
