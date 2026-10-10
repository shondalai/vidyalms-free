<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Organisation\ExportOrganisationAudit;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationPrivacy;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrganisationLearningEvents;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

final class OrganisationPrivacyHandler implements HandlerInterface
{
    public function __construct(
        private readonly string $operation,
        private readonly OrganisationPrivacy $privacy,
        private readonly ExportOrganisationAudit $audit,
        private readonly IdempotentExecution $idempotent,
        private readonly DatabasePort $database,
        private readonly OrganisationLearningEvents $events,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $actor = $request->principal;
        $id = $request->intPathParam('id');
        $headers = ['Cache-Control' => 'private, no-store'];
        if ($this->operation === 'audit') {
            $result = $this->audit->execute(
                $actor,
                $id,
                $request->queryInt('page', 1),
                $request->queryInt('per_page', 100),
                $request->queryInt('snapshot_id', 0),
                $request->queryString('event_key')
            );
            return ApiResponse::binary(
                $result['csv'],
                'text/csv; charset=UTF-8',
                'organization-' . $id . '-audit-page-' . $result['page'] . '.csv',
                $headers + ['X-Total-Count' => (string) $result['total'], 'X-Page' => (string) $result['page'],
                'X-Per-Page' => (string) $result['per_page'],
                'X-Snapshot-Id' => (string) $result['snapshot_id']],
                'attachment'
            );
        }
        if ($this->operation === 'retention') {
            // A PUT and not a PATCH: a retention policy is one decision an
            // organisation makes as a whole, and a partial edit that left a
            // duration behind from a previous policy would be a duration nobody
            // chose. The refusal below it is the one the design names, and it
            // is thrown from the use case after the attempt is recorded.
            return ApiResponse::ok($this->privacy->setRetention($actor, $id, $request->body))
                ->withHeaders($headers);
        }
        if ($this->operation !== 'erase') {
            return ApiResponse::ok($this->operation === 'catalogue' ? $this->privacy->catalogue($actor, $id)
                : $this->privacy->export(
                    $actor,
                    $id,
                    $request->queryString('contributor', 'core-organisations'),
                    $request->queryString('record_class', 'memberships'),
                    $request->queryInt('page', 1),
                    $request->queryInt('per_page', 25)
                ))
                ->withHeaders($headers);
        }
        $memberId = $request->intPathParam('member_id');
        $this->privacy->requireTarget($actor, $id, $memberId);
        try {
            $response = $this->idempotent->runAtomic(
                $request,
                'org.privacy.' . hash('sha256', $request->path),
                $this->database,
                function () use ($request, $actor, $id, $memberId): ApiResponse {
                    $result = $this->privacy->erase($actor, $id, $memberId, $request->body);
                    return new ApiResponse($result['state'] === 'cleanup_queued' ? 202 : 200, $result);
                },
                ['body' => $request->body]
            );
        } catch (\Throwable $failure) {
            $this->events->discard();
            throw $failure;
        }
        $this->events->afterCommit();
        return $response->withHeaders($headers);
    }
}
