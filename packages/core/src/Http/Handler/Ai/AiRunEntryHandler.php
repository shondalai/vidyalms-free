<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Ai;

use Shondalai\VidyaLMS\Core\Application\Ai\AcceptAiDraft;
use Shondalai\VidyaLMS\Core\Application\Ai\AiDraftReview;
use Shondalai\VidyaLMS\Core\Application\Ai\AiRunRequest;
use Shondalai\VidyaLMS\Core\Application\Ai\EstimateAiRun;
use Shondalai\VidyaLMS\Core\Application\Ai\QueueAiJob;
use Shondalai\VidyaLMS\Core\Application\Ai\StartAiRun;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScope;
use Shondalai\VidyaLMS\Core\Application\Organisation\OrgScopeResolver;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Presenter\AiPresenter;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Ai\SqlAiReadModel;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;
use Shondalai\VidyaLMS\Core\Registry\Ai\AiTaskRequest;

/**
 * The four entry points that spend something: the estimate, the interactive
 * run, the queued job and the one write of an artefact.
 *
 * They are their own handler rather than four more branches of the reading one,
 * because they are the only routes on this surface that reach the pipeline.
 * Everything else answers from rows.
 *
 * Two rules live here rather than in the use cases, and both are about the
 * request rather than about the run.
 *
 * **The body never carries an organisation, a principal or a run state.** The
 * scope is read from what the caller was authorised for, so an id in a payload
 * can never become a tenant. That is the same rule the job use case already
 * applies one layer down, applied at the door as well.
 *
 * **Accepting a draft with blocking findings needs an explicit acknowledgement**,
 * because rejection is the default state of a draft and an Accept button on its
 * own is not a control. The findings are counted from the rows rather than
 * trusted from the client, so a caller cannot acknowledge findings it was never
 * shown by simply not asking for them.
 */
final class AiRunEntryHandler implements HandlerInterface
{
    public const RESOURCE_ESTIMATE = 'estimate';
    public const RESOURCE_RUN = 'run';
    public const RESOURCE_JOB = 'job';
    public const RESOURCE_ACCEPT = 'accept';

    /** @var list<string> */
    private const SCOPES = ['site', 'course', 'cohort', 'organisation'];

    public function __construct(
        private readonly string $resource,
        private readonly EstimateAiRun $estimate,
        private readonly StartAiRun $start,
        private readonly QueueAiJob $queue,
        private readonly AcceptAiDraft $accept,
        private readonly AiDraftReview $drafts,
        private readonly SqlAiReadModel $reads,
        private readonly IdempotentExecution $idempotent,
        private readonly DatabasePort $database,
        /**
         * Where an organisation scope is minted, when the caller names one.
         *
         * Trailing and defaulted so a handler assembled by hand still
         * constructs; absent, an organisation-scoped run is refused rather
         * than run untenanted, because a run that answered null here stamped
         * `organisation_id` NULL on the ledger, counted its spend site-wide
         * and vanished from every read narrowed on the tenant.
         */
        private readonly ?OrgScopeResolver $orgScopes = null,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return match ($this->resource) {
            // An estimate writes no domain row and spends nothing, so it takes
            // no idempotency key: replaying it is free by construction.
            self::RESOURCE_ESTIMATE => ApiResponse::ok($this->estimateRun($request)),
            self::RESOURCE_RUN => $this->write(
                $request,
                'ai.run.start',
                fn (): ApiResponse => ApiResponse::ok($this->startRun($request)),
            ),
            self::RESOURCE_JOB => $this->write(
                $request,
                'ai.job.queue',
                fn (): ApiResponse => new ApiResponse(202, $this->queueJob($request)),
            ),
            self::RESOURCE_ACCEPT => $this->write(
                $request,
                'ai.draft.accept',
                fn (): ApiResponse => ApiResponse::ok($this->acceptDraft($request)),
            ),
            default => throw ApiProblem::notFound(),
        };
    }

    /**
     * The estimate, which is the dry run and therefore the privacy artefact: it
     * resolves the grounding set, renders the exact redacted payload the active
     * policy permits, writes the run in state previewed and calls no bridge.
     *
     * @return array<string, mixed>
     */
    private function estimateRun(ApiRequest $request): array
    {
        $report = $this->estimate->execute($this->runRequest($request, false));
        $taskKey = self::requiredString($request->body, 'task_key');

        return [
            'run_id' => $report->runId,
            'task_key' => $taskKey,
            'allowed' => $report->refusal === null,
            'estimate' => ['unit' => $report->spent->unit, 'amount' => $report->spent->amount],
            'route_configured' => $this->routeConfigured($taskKey),
            'refusal' => $report->refusal === null ? null : [
                'kind' => $report->refusal->kind->value,
                'message_key' => $report->refusal->messageKey,
                'parameters' => $report->refusal->parameters,
            ],
            'redaction_manifest' => $report->manifest,
            'inputs_digest' => $report->inputsDigest,
            'defer_until' => $report->deferUntil?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The interactive path. The run row is read back rather than assembled from
     * the report, so the shape a caller receives here is byte for byte the one
     * the run log answers with later.
     *
     * @return array<string, mixed>
     */
    private function startRun(ApiRequest $request): array
    {
        $report = $this->start->execute($this->runRequest($request, true));
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $row = $this->reads->run($report->runId) ?? throw EntityNotFoundException::for('AiRun', $report->runId);

        return AiPresenter::run(
            $row,
            $this->reads->attempts($report->runId),
            $this->reads->draftsOfRun($report->runId),
            $this->reads->subjectCount($report->runId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function queueJob(ApiRequest $request): array
    {
        $scopeType = self::scope($request->body['scope_type'] ?? null);
        $scopeId = $request->body['scope_id'] ?? null;

        if (!\is_int($scopeId) || $scopeId < 0) {
            throw ValidationException::forField('scope_id', 'names what this job runs over');
        }

        $job = $this->queue->execute(
            $request->principal,
            self::optionalString($request->body['task_key'] ?? null),
            self::optionalString($request->body['recipe_key'] ?? null),
            $scopeType,
            $scopeId,
            \is_array($request->body['options'] ?? null) ? $request->body['options'] : [],
            ($request->body['dry_run'] ?? false) === true,
            self::optionalString($request->body['placement_key'] ?? null),
            $this->orgScopeFor($request, $scopeType, $scopeId),
            $scopeType === 'course' && $scopeId > 0 ? $scopeId : null,
        );

        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $row = $this->reads->job($job->id) ?? throw EntityNotFoundException::for('AiJob', $job->id);

        return AiPresenter::job($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function acceptDraft(ApiRequest $request): array
    {
        $draftId = $request->intPathParam('id');
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
        $row = $this->reads->draft($draftId) ?? throw EntityNotFoundException::for('AiDraft', $draftId);
        $blocking = (int) ($row['findings_blocking'] ?? 0);

        if ($blocking > 0 && ($request->body['acknowledge_blocking'] ?? false) !== true) {
            throw ConflictException::with(
                sprintf(
                    'This draft carries %d blocking finding(s). Accepting one is a second, explicit decision.',
                    // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                    $blocking,
                ),
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                ['reason' => 'ai.draft.blocking_findings', 'findings_blocking' => $blocking],
            );
        }

        $artefact = $this->accept->execute($request->principal, $draftId);
        $provenance = $this->reads->provenanceFor($artefact->entityType, $artefact->entityId);

        return [
            'draft' => $this->drafts->get($request->principal, $draftId),
            'provenance' => $provenance === [] ? null : AiPresenter::provenance($provenance[0]),
            'artefact' => [
                'extension_point' => $artefact->extensionPoint,
                'provider_key' => $artefact->providerKey,
                'entity_type' => $artefact->entityType,
                'entity_id' => $artefact->entityId,
            ],
        ];
    }

    /**
     * The caller's body as the pipeline's own request object.
     *
     * `dry_run` is honoured on the run path and ignored on the estimate path,
     * which forces it on regardless: an estimate route that could be talked into
     * spending money by a payload that set the flag the other way is not an
     * estimate.
     */
    private function runRequest(ApiRequest $request, bool $honourDryRun): AiRunRequest
    {
        $body = $request->body;
        $taskKey = self::requiredString($body, 'task_key');
        $scopeType = self::scope($body['scope_type'] ?? null);
        $scopeId = \is_int($body['scope_id'] ?? null) ? $body['scope_id'] : null;
        $courseId = \is_int($body['course_id'] ?? null) ? $body['course_id'] : null;

        if ($courseId === null && $scopeType === 'course' && $scopeId !== null && $scopeId > 0) {
            $courseId = $scopeId;
        }

        $task = AiTaskRequest::create(
            self::optionalString($body['subject_type'] ?? null) ?? 'course',
            \is_int($body['subject_id'] ?? null) ? $body['subject_id'] : null,
            \is_array($body['options'] ?? null) ? $body['options'] : [],
            self::groundingKeys($body['grounding_keys'] ?? null),
            self::optionalString($body['intent'] ?? null),
        );

        return AiRunRequest::create(
            $request->principal,
            $taskKey,
            $task,
            self::optionalString($body['placement_key'] ?? null),
            $scopeType,
            $scopeId,
            $courseId,
            $this->orgScopeFor($request, $scopeType, $scopeId),
            null,
            $honourDryRun && ($body['dry_run'] ?? false) === true,
        );
    }

    /**
     * The organisation scope this run is raised under, minted through the
     * shipped resolver and never taken from the payload.
     *
     * A run naming `scope_type: organisation` used to pass null here, so the
     * resolver was never asked, the ledger row read `organisation_id: null`,
     * the tenant's spend was counted against the site and every
     * organisation-narrowed read missed the run. A scope is minted only after
     * the resolver's check, and a scope that cannot be minted refuses.
     */
    private function orgScopeFor(ApiRequest $request, string $scopeType, ?int $scopeId): ?OrgScope
    {
        if ($scopeType !== 'organisation') {
            return null;
        }

        if ($scopeId === null || $scopeId <= 0) {
            throw ValidationException::forField('scope_id', 'names the organisation this run is raised in');
        }

        if ($this->orgScopes === null) {
            throw ApiProblem::notFound();
        }

        return $this->orgScopes->require($request->principal, $scopeId);
    }

    private function routeConfigured(string $taskKey): bool
    {
        return $this->reads->routePage($taskKey, null, true, 1, 0)['total'] > 0;
    }

    /**
     * @param callable(): ApiResponse $operation
     */
    private function write(ApiRequest $request, string $routeName, callable $operation): ApiResponse
    {
        return $this->idempotent->runAtomic(
            $request,
            $routeName,
            $this->database,
            $operation,
            ['path' => $request->path, 'body' => $request->body],
        );
    }

    /**
     * @return list<string>
     */
    private static function groundingKeys(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $keys = [];

        foreach ($value as $key) {
            if (\is_string($key) && $key !== '') {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function requiredString(array $body, string $field): string
    {
        $value = $body[$field] ?? null;

        if (!\is_string($value) || trim($value) === '') {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw ValidationException::forField($field, 'is required');
        }

        return trim($value);
    }

    private static function optionalString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Absent means site, which is the default the run request already carries.
     * Only a value that is present and wrong is refused.
     */
    private static function scope(mixed $value): string
    {
        if ($value === null) {
            return 'site';
        }

        if (!\is_string($value) || !\in_array($value, self::SCOPES, true)) {
            throw ValidationException::forField('scope_type', 'must be site, course, cohort or organisation');
        }

        return $value;
    }
}
