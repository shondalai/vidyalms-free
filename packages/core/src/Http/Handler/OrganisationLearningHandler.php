<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationLearning;
use Shondalai\VidyaLMS\Core\Application\Organisation\ManageOrganisationSeats;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationInput;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningEvents;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

final class OrganisationLearningHandler implements HandlerInterface
{
    public function __construct(
        private readonly string $operation,
        private readonly ManageOrganisationLearning $learning,
        private readonly ManageOrganisationSeats $seats,
        private readonly OrgScopeResolver $access,
        private readonly IdempotentExecution $idempotent,
        private readonly DatabasePort $database,
        private readonly OrganisationLearningEvents $events,
        /**
         * Moving a seat between pools, as one use case.
         *
         * Optional so an adapter or a harness built before the route existed
         * still constructs a handler; the route is only registered when it is
         * supplied, so a null here is unreachable from a registered route.
         */
        private readonly ?\Shondalai\VidyaLMS\Core\Application\Organisation\TransferSeat $transfer = null,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $orgId = $request->intPathParam('id');
        $poolId = isset($request->pathParams['pool_id']) ? $request->intPathParam('pool_id') : 0;
        $actor = $request->principal;
        $page = $request->queryInt('page', 1);
        $perPage = $request->queryInt('per_page', 25);
        if ($request->method === 'GET') {
            return ApiResponse::ok(match ($this->operation) {
                'releases' => $this->learning->releases($actor, $orgId, $page, $perPage),
                'learning' => $this->learning->list($actor, $orgId, $page, $perPage, $request->queryString('status')),
                'summary' => $this->learning->summary($actor, $orgId),
                'cleanup_jobs' => $this->learning->cleanupJobs($actor, $orgId, $page, $perPage),
                'pools' => $this->seats->pools($actor, $orgId, $page, $perPage),
                'pool' => $this->seats->getPool($actor, $orgId, $poolId),
                'assignments' => $this->seats->assignments($actor, $orgId, $poolId, $page, $perPage),
                'ledger' => $this->seats->ledger($actor, $orgId, $poolId, $page, $perPage),
                'reconciliation' => $this->seats->reconciliation($actor, $orgId, $poolId, $request->queryString('as_at')),
                default => throw new \LogicException('Unknown organisation learning read operation.'),
            });
        }
        $action = \in_array($this->operation, ['release_course', 'grant', 'revoke', 'retry_cleanup'], true) ? 'learning' : 'seats';
        $this->access->require($actor, $orgId, $action);
        if (\in_array($this->operation, ['release_course', 'create_pool', 'update_pool'], true)) {
            $this->access->requireSiteManager($actor);
        }
        if ($this->operation === 'grant') {
            $this->learning->requireTarget($actor, $orgId, userId: OrganisationInput::integer($request->body, 'user_id'));
        } elseif ($this->operation === 'revoke') {
            $this->learning->requireTarget($actor, $orgId, entitlementId: $request->intPathParam('entitlement_id'));
        } elseif ($this->operation === 'assign') {
            $this->seats->requireTarget($actor, $orgId, userId: OrganisationInput::integer($request->body, 'user_id'));
        } elseif (\in_array($this->operation, ['release_seat', 'transfer_seat'], true)) {
            $this->seats->requireTarget($actor, $orgId, assignmentId: $request->intPathParam('assignment_id'));
        }
        try {
            $response = $this->idempotent->runAtomic(
                $request,
                'org.learning.' . hash('sha256', $request->method . ':' . $request->path),
                $this->database,
                function () use ($request, $actor, $orgId, $poolId): ApiResponse {
                    if ($this->operation === 'retry_cleanup') {
                        OrganisationInput::only($request->body, ['expected_revision']);
                    }
                    if ($this->operation === 'revoke') {
                        // The free organisation grant revoke is a revoking
                        // route like the seat ones and had no ceremony at all,
                        // so a learner mid-attempt was abandoned with no grade
                        // and nothing on the attempt saying who ended it.
                        OrganisationInput::only($request->body, ['expected_revision', 'attempt_disposition']);
                    }
                    if ($this->operation === 'release_seat') {
                        // The revoking routes carry the disposition of any
                        // attempt the holder still has open, which defaults to
                        // submitting and grading it: the work is theirs and
                        // ending their access is not a reason to lose it. The
                        // reason is what a registered third-party reason key
                        // reaches the ledger through; without it the registry
                        // could be read and never written.
                        OrganisationInput::only($request->body, ['expected_revision', 'attempt_disposition', 'reason_key']);
                    }
                    $result = match ($this->operation) {
                        'retry_cleanup' => $this->learning->retryCleanup($actor, $orgId, $request->intPathParam('job_id'),
                            OrganisationInput::integer($request->body, 'expected_revision')),
                        'release_course' => $this->learning->releaseCourse($actor, $orgId, $request->body),
                        'grant' => $this->learning->grant($actor, $orgId, $request->body),
                        'revoke' => $this->learning->revoke($actor, $orgId, $request->intPathParam('entitlement_id'),
                            OrganisationInput::integer($request->body, 'expected_revision'), $request->body),
                        'create_pool' => $this->seats->savePool($actor, $orgId, null, $request->body),
                        'update_pool' => $this->seats->savePool($actor, $orgId, $poolId, $request->body),
                        'assign' => $this->seats->assign($actor, $orgId, $poolId, $request->body),
                        'release_seat' => $this->seats->release($actor, $orgId, $request->intPathParam('assignment_id'),
                            OrganisationInput::integer($request->body, 'expected_revision'), $request->body),
                        // One use case, and the route is a POST on the
                        // assignment rather than a pair of calls, because the
                        // acquiring and releasing halves have to be in one
                        // transaction and two routes cannot be.
                        'transfer_seat' => ($this->transfer ?? throw new \LogicException('Seat transfer is not wired.'))
                            ->execute($actor, $orgId, $request->intPathParam('assignment_id'), $request->body),
                        default => throw new \LogicException('Unknown organisation learning mutation.'),
                    };

                    return ApiResponse::ok($result);
                },
                ['body' => $request->body]
            );
        } catch (\Throwable $error) {
            $this->events->discard();
            throw $error;
        }
        $this->events->afterCommit();

        return $response;
    }
}
