<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Ai;

use Shondalai\VidyaLMS\Core\Application\Ai\AiPolicyAdmin;
use Shondalai\VidyaLMS\Core\Application\Ai\AiRouteAdmin;
use Shondalai\VidyaLMS\Core\Application\Ai\AiRunLog;
use Shondalai\VidyaLMS\Core\Application\Ai\AiStopControl;
use Shondalai\VidyaLMS\Core\Application\Ai\GetAiUsageReport;
use Shondalai\VidyaLMS\Core\Application\Ai\RecallAiRun;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * The administration half of the AI surface: the four enablement levels, the
 * stop above them, route administration, the data-use policy versions, the
 * usage report, the run log and the recall.
 *
 * Every route here requires manage. The design asks for the usage report to sit
 * behind its own capability, and it does: the registered report declares what a
 * caller must hold, so narrowing it later is a provider edit rather than a
 * route edit.
 *
 * The stop is a write and takes a required key like every other, because
 * setting a safety control twice must record one act and not two: a screen that
 * retries after a timeout would otherwise overwrite who stopped the site.
 */
final class ManageAiHandler implements HandlerInterface
{
    public const RESOURCE_ENABLEMENT = 'enablement';
    public const RESOURCE_STOP = 'stop';
    public const RESOURCE_ROUTES = 'routes';
    public const RESOURCE_ROUTE = 'route';
    public const RESOURCE_POLICIES = 'policies';
    public const RESOURCE_POLICY = 'policy';
    public const RESOURCE_USAGE = 'usage';
    public const RESOURCE_RUNS = 'runs';
    public const RESOURCE_RUN = 'run';
    public const RESOURCE_RECALL = 'recall';

    private const DEFAULT_PER_PAGE = 25;

    public function __construct(
        private readonly string $resource,
        private readonly AiStopControl $control,
        private readonly AiRouteAdmin $routes,
        private readonly AiPolicyAdmin $policies,
        private readonly GetAiUsageReport $usage,
        private readonly AiRunLog $runs,
        private readonly RecallAiRun $recall,
        private readonly IdempotentExecution $idempotent,
        private readonly DatabasePort $database,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return match ($this->resource) {
            self::RESOURCE_ENABLEMENT => $this->enablement($request),
            self::RESOURCE_STOP => $this->stop($request),
            self::RESOURCE_ROUTES => $this->routes($request),
            self::RESOURCE_ROUTE => $this->route($request),
            self::RESOURCE_POLICIES => $this->policies($request),
            self::RESOURCE_POLICY => $this->write(
                $request,
                'ai.policy.update',
                fn (): ApiResponse => ApiResponse::ok($this->policies->update(
                    $request->principal,
                    $request->intPathParam('version'),
                    $request->body,
                )),
            ),
            self::RESOURCE_USAGE => ApiResponse::ok($this->usage->execute(
                $request->principal,
                self::optional($request->queryString('from')),
                self::optional($request->queryString('to')),
                self::optionalInt($request, 'organisation_id'),
            )),
            self::RESOURCE_RUNS => ApiResponse::ok($this->runs->runs(
                $request->principal,
                [
                    'state' => self::optional($request->queryString('state')),
                    'task_key' => self::optional($request->queryString('task_key')),
                    'placement_key' => self::optional($request->queryString('placement_key')),
                    'principal_user_id' => self::optionalInt($request, 'principal_user_id'),
                    'course_id' => self::optionalInt($request, 'course_id'),
                    'organisation_id' => self::optionalInt($request, 'organisation_id'),
                    'dry_run' => self::optionalFlag($request),
                ],
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            )),
            self::RESOURCE_RUN => ApiResponse::ok($this->runs->run($request->principal, $request->intPathParam('id'))),
            self::RESOURCE_RECALL => $this->write(
                $request,
                'ai.run.recall',
                fn (): ApiResponse => ApiResponse::ok($this->recallRun($request)),
            ),
            default => throw ApiProblem::notFound(),
        };
    }

    /**
     * A recall with no confirmation previews and changes nothing; a recall with
     * one stamps every provenance row of the run superseded and leaves it in
     * place. The two are one route because they answer the same question, and
     * the half that changes state is typed out rather than clicked.
     *
     * @return array<string, mixed>
     */
    private function recallRun(ApiRequest $request): array
    {
        $id = $request->intPathParam('id');
        $confirmation = $request->body['confirmation'] ?? null;

        if (!\is_string($confirmation) || $confirmation === '') {
            return $this->recall->preview($request->principal, $id);
        }

        $reason = $request->body['reason'] ?? null;

        return $this->recall->execute(
            $request->principal,
            $id,
            $confirmation,
            \is_string($reason) ? $reason : '',
        );
    }

    private function enablement(ApiRequest $request): ApiResponse
    {
        if ($request->method === 'GET') {
            return ApiResponse::ok($this->control->enablement($request->principal));
        }

        return $this->write(
            $request,
            'ai.enablement.set',
            fn (): ApiResponse => ApiResponse::ok($this->control->setEnablement($request->principal, $request->body)),
        );
    }

    private function stop(ApiRequest $request): ApiResponse
    {
        if ($request->method === 'DELETE') {
            return $this->write(
                $request,
                'ai.stop.clear',
                fn (): ApiResponse => ApiResponse::ok($this->control->clear($request->principal)),
            );
        }

        return $this->write(
            $request,
            'ai.stop.set',
            fn (): ApiResponse => ApiResponse::ok($this->control->stop($request->principal, $request->body)),
        );
    }

    private function routes(ApiRequest $request): ApiResponse
    {
        if ($request->method === 'GET') {
            return ApiResponse::ok($this->routes->list(
                $request->principal,
                self::optional($request->queryString('task_key')),
                self::optional($request->queryString('bridge_key')),
                self::optionalFlag($request, 'enabled'),
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            ));
        }

        return $this->write(
            $request,
            'ai.route.create',
            fn (): ApiResponse => ApiResponse::created($this->routes->create($request->principal, $request->body)),
        );
    }

    private function route(ApiRequest $request): ApiResponse
    {
        $id = $request->intPathParam('id');

        if ($request->method === 'DELETE') {
            return $this->write(
                $request,
                'ai.route.delete',
                fn (): ApiResponse => ApiResponse::ok($this->routes->delete($request->principal, $id)),
            );
        }

        return $this->write(
            $request,
            'ai.route.update',
            fn (): ApiResponse => ApiResponse::ok($this->routes->update($request->principal, $id, $request->body)),
        );
    }

    private function policies(ApiRequest $request): ApiResponse
    {
        if ($request->method === 'GET') {
            return ApiResponse::ok($this->policies->list(
                $request->principal,
                self::optional($request->queryString('state')),
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            ));
        }

        return $this->write(
            $request,
            'ai.policy.create',
            fn (): ApiResponse => ApiResponse::created($this->policies->create($request->principal, $request->body)),
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

    private static function optional(string $value): ?string
    {
        return $value === '' ? null : $value;
    }

    private static function optionalInt(ApiRequest $request, string $name): ?int
    {
        $value = $request->queryInt($name, 0);

        return $value > 0 ? $value : null;
    }

    /**
     * Absent means both, which is why this is not a queryBool default: a
     * preview and a real run are different questions, and a filter that
     * silently meant "real runs only" would hide every dry run.
     */
    private static function optionalFlag(ApiRequest $request, string $name = 'dry_run'): ?bool
    {
        $raw = $request->query[$name] ?? null;

        if (!\is_string($raw) || $raw === '') {
            return null;
        }

        return \in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }
}
