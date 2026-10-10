<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationConnections;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationJoining;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * The thirteen organisation identity routes, one handler.
 *
 * One class rather than thirteen for the reason the import handler is one for
 * six: they share the organisation id, they share the child id, and thirteen
 * files would be thirteen places to get the tenancy check subtly different. The
 * use cases do the checks; this is transport, and it is deliberately dull.
 */
final class OrganisationIdentityHandler implements HandlerInterface
{
    public function __construct(
        private readonly string $operation,
        private readonly ManageOrganisationConnections $connections,
        private readonly ManageOrganisationJoining $joining,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $orgId = $request->intPathParam('id');
        $actor = $request->principal;

        return match ($this->operation) {
            'connections' => ApiResponse::ok(['items' => $this->connections->connections($actor, $orgId)]),
            'create_connection' => ApiResponse::created(
                $this->connections->create($actor, $orgId, $request->body)
            ),
            'update_connection' => ApiResponse::ok($this->connections->update(
                $actor,
                $orgId,
                $request->intPathParam('connection_id'),
                $request->body,
            )),
            'decide_connection' => ApiResponse::ok($this->connections->decide(
                $actor,
                $orgId,
                $request->intPathParam('connection_id'),
                $request->body,
            )),
            'delegate_connection' => ApiResponse::ok($this->connections->delegate(
                $actor,
                $orgId,
                $request->intPathParam('connection_id'),
                $request->body,
            )),
            'links' => ApiResponse::ok(['items' => $this->connections->links(
                $actor,
                $orgId,
                $this->stateFilter($request),
                $request->queryInt('per_page', 50),
            )]),
            'resolve_link' => ApiResponse::ok($this->connections->resolveLink(
                $actor,
                $orgId,
                $request->intPathParam('link_id'),
                $request->body,
            )),
            'domains' => ApiResponse::ok(['items' => $this->joining->domains($actor, $orgId)]),
            'claim_domain' => ApiResponse::created($this->joining->claimDomain($actor, $orgId, $request->body)),
            'revoke_domain' => ApiResponse::ok($this->joining->revokeDomain(
                $actor,
                $orgId,
                $request->intPathParam('domain_id'),
            )),
            'join_rules' => ApiResponse::ok(['items' => $this->joining->joinRules($actor, $orgId)]),
            'add_join_rule' => ApiResponse::created($this->joining->addJoinRule($actor, $orgId, $request->body)),
            'retire_join_rule' => ApiResponse::ok($this->joining->retireJoinRule(
                $actor,
                $orgId,
                $request->intPathParam('rule_id'),
            )),
            default => throw new \LogicException('Unknown organisation identity operation.'),
        };
    }

    /**
     * The optional state filter, absent rather than empty when it was not
     * asked for.
     *
     * An empty string is a filter matching nothing, and a caller who typed
     * "?state=" meant "no filter" rather than "match the empty state".
     */
    private function stateFilter(ApiRequest $request): ?string
    {
        $state = trim($request->queryString('state', ''));

        return $state === '' ? null : $state;
    }
}
