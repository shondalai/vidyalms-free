<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationInvitations;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationInput;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

final class OrganisationInvitationsHandler implements HandlerInterface
{
    public function __construct(
        private readonly string $operation,
        private readonly ManageOrganisationInvitations $invitations,
        private readonly OrgScopeResolver $access,
        private readonly IdempotentExecution $idempotent,
        private readonly DatabasePort $database,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $orgId = isset($request->pathParams['id']) ? $request->intPathParam('id') : 0;
        $actor = $request->principal;

        // The three routes whose use case takes scalars rather than a body
        // fold their own input, so "every organisation write rejects a key the
        // route does not name" is true of the whole surface rather than of the
        // twelve writes that happen to call only() themselves. It runs before
        // the attempt counter, so a malformed body does not spend one of a
        // caller's tries. Claim and accept are the weakest place on this
        // surface to be lenient: they are the only organisation routes a
        // caller with no membership can reach at all.
        $named = match ($this->operation) {
            'revoke' => ['expected_revision'],
            'claim' => ['token'],
            'accept' => ['claim_id', 'code'],
            default => null,
        };

        if ($named !== null) {
            OrganisationInput::only($request->body, $named);
        }

        if (\in_array($this->operation, ['claim', 'accept'], true)) {
            // Outside runAtomic: a failed code cannot roll back its own rate
            // counter. Secrets only enter the one-way request fingerprint.
            $subject = $this->operation === 'claim' ? OrganisationInput::text($request->body, 'token', 64)
                : (string) OrganisationInput::integer($request->body, 'claim_id');
            $this->invitations->limitAttempt($actor, $this->operation, $subject, $request->clientAddress);
        } else {
            $scope = $this->access->require($actor, $orgId, 'people');
            if ($scope->unitId !== null) {
                throw new \Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException(
                    'Organisation-wide invitations require an organisation-wide administrator.'
                );
            }
        }
        if ($this->operation === 'list') {
            return ApiResponse::ok($this->invitations->list(
                $actor,
                $orgId,
                $request->queryInt('page', 1),
                $request->queryInt('per_page', 25),
                $request->queryString('state')
            ));
        }

        if ($this->operation === 'issue') {
            $this->invitations->limitIssue($actor, $orgId, OrganisationInput::text($request->body, 'email', 190));
        }

        return $this->idempotent->runAtomic(
            $request,
            'orgi.' . hash('sha256', $request->method . ':' . $request->path),
            $this->database,
            function () use ($request, $actor, $orgId): ApiResponse {
                $result = match ($this->operation) {
                    'issue' => $this->invitations->issue($actor, $orgId, $request->body, rateLimitAlreadyConsumed: true),
                    'revoke' => $this->invitations->revoke($actor, $orgId, $request->intPathParam('invitation_id'),
                        OrganisationInput::integer($request->body, 'expected_revision')),
                    'claim' => $this->invitations->claim($actor, OrganisationInput::text($request->body, 'token', 64)),
                    'accept' => $this->invitations->accept($actor, OrganisationInput::integer($request->body, 'claim_id'),
                        OrganisationInput::text($request->body, 'code', 12)),
                    default => throw new \LogicException('Unsupported invitation operation.'),
                };

                return \in_array($this->operation, ['issue', 'claim'], true)
                    ? ApiResponse::created($result) : ApiResponse::ok($result);
            },
            ['body' => $request->body]
        );
    }
}
