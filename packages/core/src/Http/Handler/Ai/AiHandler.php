<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Ai;

use Shondalai\VidyaLMS\Core\Application\Ai\AiDraftReview;
use Shondalai\VidyaLMS\Core\Application\Ai\AiPlacementCatalogue;
use Shondalai\VidyaLMS\Core\Application\Ai\AiPolicyCentre;
use Shondalai\VidyaLMS\Core\Application\Ai\AiRunLog;
use Shondalai\VidyaLMS\Core\Application\Ai\GetMyAiActivity;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * The caller-facing half of the AI surface, in one handler keyed by the
 * resource the route names.
 *
 * One class rather than eleven, for the reason the automation handler already
 * gives: every route here is the same three lines, read the query or the body,
 * call the use case, relay the shape. The rules live in the use cases, which is
 * where a handler is allowed to have none of its own.
 *
 * Every write commits its idempotency claim inside its own transaction, so the
 * key is required rather than accepted: accepting a policy version, editing a
 * draft and deciding about one are each an act whose replay must return the
 * first answer rather than run again. A missing key is therefore 400, which is
 * what tells a client to fix its transport rather than its payload.
 *
 * The one rule this class does carry is a negative: no route here returns a
 * draft payload to a caller who is not authorised to author. The draft routes
 * ask the use case, which asks teach on the owning course or manage, and
 * `/me/ai-activity` returns the redaction manifest and never a draft. A draft
 * reachable by a learner read path would break the redline the whole design
 * rests on.
 */
final class AiHandler implements HandlerInterface
{
    public const RESOURCE_PLACEMENTS = 'placements';
    public const RESOURCE_POLICY = 'policy';
    public const RESOURCE_POLICY_ACCEPTANCE = 'policy_acceptance';
    public const RESOURCE_RUN = 'run';
    public const RESOURCE_JOBS = 'jobs';
    public const RESOURCE_JOB = 'job';
    public const RESOURCE_DRAFTS = 'drafts';
    public const RESOURCE_DRAFT = 'draft';
    public const RESOURCE_DRAFT_REJECT = 'draft_reject';
    public const RESOURCE_DRAFT_DISCARD = 'draft_discard';
    public const RESOURCE_PROVENANCE = 'provenance';
    public const RESOURCE_MY_ACTIVITY = 'my_activity';

    private const DEFAULT_PER_PAGE = 25;

    public function __construct(
        private readonly string $resource,
        private readonly AiPlacementCatalogue $placements,
        private readonly AiPolicyCentre $policies,
        private readonly AiDraftReview $drafts,
        private readonly AiRunLog $runs,
        private readonly GetMyAiActivity $activity,
        private readonly IdempotentExecution $idempotent,
        private readonly DatabasePort $database,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return match ($this->resource) {
            self::RESOURCE_PLACEMENTS => ApiResponse::ok($this->placements->execute(
                $request->principal,
                self::optional($request->queryString('surface_key')),
                self::anchors($request),
                self::scopeType($request),
                max(0, $request->queryInt('scope_id', 0)),
            )),
            self::RESOURCE_POLICY => ApiResponse::ok($this->policies->state($request->principal)),
            self::RESOURCE_POLICY_ACCEPTANCE => $this->write(
                $request,
                'ai.policy.accept',
                fn (): ApiResponse => ApiResponse::ok($this->policies->accept($request->principal, $request->body)),
            ),
            self::RESOURCE_RUN => ApiResponse::ok($this->runs->run($request->principal, $request->intPathParam('id'))),
            self::RESOURCE_JOBS => ApiResponse::ok($this->runs->jobs(
                $request->principal,
                [
                    'status' => self::optional($request->queryString('status')),
                    'scope_type' => self::optional($request->queryString('scope_type')),
                    'scope_id' => self::optionalInt($request, 'scope_id'),
                ],
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            )),
            self::RESOURCE_JOB => ApiResponse::ok($this->runs->job($request->principal, $request->intPathParam('id'))),
            self::RESOURCE_DRAFTS => ApiResponse::ok($this->drafts->list(
                $request->principal,
                [
                    'state' => self::optional($request->queryString('state')),
                    'draft_kind' => self::optional($request->queryString('draft_kind')),
                    'course_id' => self::optionalInt($request, 'course_id'),
                    'run_id' => self::optionalInt($request, 'run_id'),
                    'job_id' => self::optionalInt($request, 'job_id'),
                ],
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            )),
            self::RESOURCE_DRAFT => $this->draft($request),
            self::RESOURCE_DRAFT_REJECT => $this->write(
                $request,
                'ai.draft.reject',
                fn (): ApiResponse => ApiResponse::ok(
                    $this->drafts->reject($request->principal, $request->intPathParam('id'), $request->body),
                ),
            ),
            self::RESOURCE_DRAFT_DISCARD => $this->write(
                $request,
                'ai.draft.discard',
                fn (): ApiResponse => ApiResponse::ok(
                    $this->drafts->discard($request->principal, $request->intPathParam('id'), $request->body),
                ),
            ),
            self::RESOURCE_PROVENANCE => ApiResponse::ok($this->runs->provenance(
                $request->principal,
                $request->pathParam('subject_type'),
                $request->intPathParam('id'),
            )),
            self::RESOURCE_MY_ACTIVITY => ApiResponse::ok($this->activity->execute(
                $request->principal,
                self::optional($request->queryString('from')),
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            )),
            default => throw ApiProblem::notFound(),
        };
    }

    private function draft(ApiRequest $request): ApiResponse
    {
        $id = $request->intPathParam('id');

        if ($request->method === 'GET') {
            return ApiResponse::ok($this->drafts->get($request->principal, $id));
        }

        return $this->write(
            $request,
            'ai.draft.update',
            fn (): ApiResponse => ApiResponse::ok($this->drafts->update($request->principal, $id, $request->body)),
        );
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
     * The anchors the running bundle publishes, repeatable. A placement whose
     * anchor is absent from this list is not offered, which is what lets a
     * workbench newer than its host degrade rather than draw into nothing.
     *
     * @return list<string>
     */
    private static function anchors(ApiRequest $request): array
    {
        $raw = $request->query['anchor'] ?? null;

        if (\is_string($raw)) {
            $raw = explode(',', $raw);
        }

        if (!\is_array($raw)) {
            return [];
        }

        $anchors = [];

        foreach ($raw as $anchor) {
            if (\is_string($anchor) && trim($anchor) !== '') {
                $anchors[] = trim($anchor);
            }
        }

        return $anchors;
    }

    private static function scopeType(ApiRequest $request): string
    {
        $scope = $request->queryString('scope_type');

        return \in_array($scope, ['site', 'course', 'cohort', 'organisation'], true) ? $scope : 'site';
    }

    private static function optional(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function optionalInt(ApiRequest $request, string $name): ?int
    {
        $value = $request->queryInt($name, 0);

        return $value > 0 ? $value : null;
    }
}
