<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationMembers;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisations;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningEvents;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/** Thin neutral handler: the organisation use cases remain the authority. */
final class OrganisationManagementHandler implements HandlerInterface
{
    public function __construct(
        private readonly string $operation,
        private readonly ManageOrganisations $organisations,
        private readonly ManageOrganisationMembers $members,
        private readonly OrgScopeResolver $access,
        private readonly IdempotentExecution $idempotent,
        private readonly DatabasePort $database,
        private readonly ?OrganisationLearningEvents $events = null,
        private readonly ?\Shondalai\VidyaLMS\Core\Application\Organisation\MyOrganisationLearning $learning = null,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $id = isset($request->pathParams['id']) ? $request->intPathParam('id') : 0;
        $memberId = isset($request->pathParams['member_id']) ? $request->intPathParam('member_id') : 0;
        $actor = $request->principal;
        $page = $request->queryInt('page', 1);
        $perPage = $request->queryInt('per_page', 25);
        if ($request->method === 'GET') {
            return ApiResponse::ok(match ($this->operation) {
                'list' => $this->organisations->list($actor, $page, $perPage, $request->queryString('search'),
                    $request->queryString('state')),
                'get' => $this->organisations->get($actor, $id),
                'mine' => $this->learning?->organisations($actor, $page, $perPage) ?? $this->organisations->mine($actor, $page, $perPage),
                'my_learning' => ($this->learning ?? throw new \LogicException('Personal learning is not composed.'))->page($actor, $id, $request->queryString('tab', 'assigned'), $page, $perPage, $request->queryString('search')),
                'members' => $this->members->list($actor, $id, $page, $perPage, $request->queryString('state')),
                'member' => $this->members->get($actor, $id, $memberId),
                'events' => $this->members->events($actor, $id, $page, $perPage),
                'roles' => $this->members->roleCatalogue($actor, $id),
                default => throw new \LogicException('Unsupported organisation read operation.'),
            })->withHeaders(['Cache-Control' => 'private, no-store']);
        }
        // Replays undergo today's scope check, not only the original writer's.
        if ($this->operation === 'create') {
            $this->access->requireSiteManager($actor);
        } else {
            $this->access->require($actor, $id, $this->operation === 'update' ? 'settings' : 'people');
        }
        if ($this->operation === 'update_member') {
            $this->members->requireVisibleMember($actor, $id, $memberId);
        } elseif ($this->operation === 'add_member') {
            $this->members->requireScopedBeneficiary(
                $actor,
                $id,
                \Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationInput::integer($request->body, 'user_id')
            );
        }

        try {
            $response = $this->idempotent->runAtomic(
                $request,
                'org.' . hash('sha256', $request->method . ':' . $request->path),
                $this->database,
                function () use ($request, $actor, $id, $memberId): ApiResponse {
                    $result = match ($this->operation) {
                        'create' => $this->organisations->create($actor, $request->body),
                        'update' => $this->organisations->update($actor, $id, $request->body),
                        'add_member' => $this->members->add($actor, $id, $request->body),
                        'update_member' => $this->members->update($actor, $id, $memberId, $request->body),
                        default => throw new \LogicException('Unsupported organisation mutation operation.'),
                    };

                    return \in_array($this->operation, ['create', 'add_member'], true)
                        ? ApiResponse::created($result) : ApiResponse::ok($result);
                },
                ['body' => $request->body]
            );
        } catch (\Throwable $error) {
            $this->events?->discard();
            throw $error;
        }
        $this->events?->afterCommit();

        return $response;
    }
}
