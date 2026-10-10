<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Automation;

use Shondalai\VidyaLMS\Core\Application\Automation\AutomationCatalogue;
use Shondalai\VidyaLMS\Core\Application\Automation\AutomationRuleAdmin;
use Shondalai\VidyaLMS\Core\Application\Automation\AutomationRulePreview;
use Shondalai\VidyaLMS\Core\Application\Automation\AutomationRuleTesting;
use Shondalai\VidyaLMS\Core\Application\Automation\AutomationRunLog;
use Shondalai\VidyaLMS\Core\Application\Automation\ReleaseAutomationStep;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * The automation surface's routes, in one handler keyed by the resource the
 * route names.
 *
 * One class rather than nine, because every one of them is the same three
 * lines: read the query or the body, call the use case, relay the shape. The
 * rules live in the use cases, which is where a handler is allowed to have
 * none of its own.
 *
 * Every write commits its idempotency claim inside its own transaction, so the
 * key is required rather than accepted: a rule save, an arming and a rehearsal
 * are each an act whose replay must return the first answer rather than run
 * again. A missing key is therefore 400, which is what tells a client to fix
 * its transport rather than its payload.
 */
final class AutomationRulesHandler implements HandlerInterface
{
    public const RESOURCE_CATALOGUE = 'catalogue';
    public const RESOURCE_RULES = 'rules';
    public const RESOURCE_RULE = 'rule';
    public const RESOURCE_PREVIEW = 'preview';
    public const RESOURCE_ARM = 'arm';
    public const RESOURCE_DISARM = 'disarm';
    public const RESOURCE_TESTS = 'tests';
    public const RESOURCE_TEST_RUN = 'test_run';
    public const RESOURCE_RUNS = 'runs';
    public const RESOURCE_RUN_ACTIONS = 'run_actions';
    public const RESOURCE_RUN_ACTION_RELEASE = 'run_action_release';

    private const DEFAULT_PER_PAGE = 25;

    public function __construct(
        private readonly string $resource,
        private readonly AutomationRuleAdmin $admin,
        private readonly AutomationCatalogue $catalogue,
        private readonly AutomationRulePreview $preview,
        private readonly AutomationRuleTesting $tests,
        private readonly AutomationRunLog $runs,
        private readonly IdempotentExecution $idempotent,
        private readonly DatabasePort $database,
        /**
         * The exit from awaiting_approval and parked. Optional only so a
         * harness that builds the handler by hand still compiles; the kernel
         * always supplies one, because a gate with no release is a step that
         * waits for ever.
         */
        private readonly ?ReleaseAutomationStep $release = null,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return match ($this->resource) {
            self::RESOURCE_CATALOGUE => ApiResponse::ok($this->catalogue->execute($request->principal)),
            self::RESOURCE_RULES => $this->rules($request),
            self::RESOURCE_RULE => $this->rule($request),
            self::RESOURCE_PREVIEW => $this->write($request, 'automation.preview', fn (): ApiResponse => ApiResponse::ok(
                $this->preview->execute($request->principal, $request->intPathParam('id'), $request->body),
            )),
            self::RESOURCE_ARM => $this->write($request, 'automation.arm', fn (): ApiResponse => ApiResponse::ok(
                $this->admin->arm($request->principal, $request->intPathParam('id'), $request->body),
            )),
            self::RESOURCE_DISARM => $this->write($request, 'automation.disarm', fn (): ApiResponse => ApiResponse::ok(
                $this->admin->disarm($request->principal, $request->intPathParam('id'), $request->body),
            )),
            self::RESOURCE_TESTS => $this->tests($request),
            self::RESOURCE_TEST_RUN => $this->write(
                $request,
                'automation.test.run',
                fn (): ApiResponse => ApiResponse::ok($this->tests->run(
                    $request->principal,
                    $request->intPathParam('id'),
                    $request->intPathParam('test_id'),
                )),
            ),
            self::RESOURCE_RUNS => ApiResponse::ok($this->runs->runs(
                $request->principal,
                $request->intPathParam('id'),
                $this->optional($request->queryString('state')),
                $this->optionalFlag($request),
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            )),
            self::RESOURCE_RUN_ACTIONS => ApiResponse::ok($this->runs->actions(
                $request->principal,
                $request->intPathParam('id'),
                $request->intPathParam('run_id'),
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            )),
            self::RESOURCE_RUN_ACTION_RELEASE => $this->write(
                $request,
                'automation.run.action.release',
                fn (): ApiResponse => ApiResponse::ok($this->releaseStep($request)),
            ),
            default => throw ApiProblem::notFound(),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function releaseStep(ApiRequest $request): array
    {
        if ($this->release === null) {
            throw ApiProblem::notFound();
        }

        $note = $request->body['note'] ?? null;

        return $this->release->execute(
            $request->principal,
            $request->intPathParam('id'),
            $request->intPathParam('run_id'),
            self::stepIndex($request),
            \is_string($note) ? $note : null,
        );
    }

    /**
     * A step index, which is zero-based.
     *
     * `intPathParam()` refuses a leading zero and refuses zero itself, because
     * every other path integer it reads is a row id and a row id of zero is a
     * client bug. A step index is a position in an ordered list and the first
     * step is 0, so reading it through that helper made the first step of every
     * run unreleasable and answered 404 for a row that was sitting there.
     */
    private static function stepIndex(ApiRequest $request): int
    {
        $raw = $request->pathParam('step_index');

        if (preg_match('/^(0|[1-9][0-9]*)$/', $raw) !== 1) {
            throw ApiProblem::notFound();
        }

        return (int) $raw;
    }

    private function rules(ApiRequest $request): ApiResponse
    {
        if ($request->method === 'GET') {
            return ApiResponse::ok($this->admin->list(
                $request->principal,
                $this->optional($request->queryString('trigger_key')),
                $this->optional($request->queryString('state')),
                $this->optional($request->queryString('action_key')),
                $this->optional($request->queryString('search')),
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            ));
        }

        return $this->write($request, 'automation.rule.create', fn (): ApiResponse => ApiResponse::created(
            $this->admin->save($request->principal, null, $request->body),
        ));
    }

    private function rule(ApiRequest $request): ApiResponse
    {
        $id = $request->intPathParam('id');

        if ($request->method === 'GET') {
            return ApiResponse::ok($this->admin->get($request->principal, $id));
        }

        if ($request->method === 'DELETE') {
            return $this->write($request, 'automation.rule.archive', fn (): ApiResponse => ApiResponse::ok(
                $this->admin->archive($request->principal, $id),
            ));
        }

        return $this->write($request, 'automation.rule.update', fn (): ApiResponse => ApiResponse::ok(
            $this->admin->save($request->principal, $id, $request->body),
        ));
    }

    private function tests(ApiRequest $request): ApiResponse
    {
        $id = $request->intPathParam('id');

        if ($request->method === 'GET') {
            return ApiResponse::ok($this->tests->list(
                $request->principal,
                $id,
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            ));
        }

        return $this->write($request, 'automation.test.save', fn (): ApiResponse => ApiResponse::ok(
            $this->tests->save($request->principal, $id, $request->body),
        ));
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

    private function optional(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    /**
     * Absent means both, which is why this is not a queryBool default: a
     * rehearsal and a real run are different questions, and a filter that
     * silently meant "real runs only" would hide every preview.
     */
    private function optionalFlag(ApiRequest $request): ?bool
    {
        $raw = $request->query['dry_run'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        return \in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
