<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Notification;

use Shondalai\VidyaLMS\Core\Application\Notification\NotificationAdmin;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationCatalogue;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/**
 * The operator surface's routes, in one handler keyed by the resource the
 * route names.
 *
 * One class rather than eleven, because every one of them is the same three
 * lines: read the query or the body, call the use case, relay the shape. The
 * rules live in the use case, which is where a handler is allowed to have
 * none of its own.
 */
final class NotificationAdminHandler implements HandlerInterface
{
    public const RESOURCE_CATALOGUE = 'catalogue';
    public const RESOURCE_RULES = 'rules';
    public const RESOURCE_RULE = 'rule';
    public const RESOURCE_QUEUE = 'queue';
    public const RESOURCE_QUEUE_CANCEL = 'queue_cancel';
    public const RESOURCE_POLICIES = 'policies';
    public const RESOURCE_SUPPRESSIONS = 'suppressions';
    public const RESOURCE_CONSENTS = 'consents';

    private const DEFAULT_PER_PAGE = 25;

    public function __construct(
        private readonly string $resource,
        private readonly NotificationAdmin $admin,
        private readonly NotificationCatalogue $catalogue,
        private readonly IdempotentExecution $idempotent,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        return match ($this->resource) {
            self::RESOURCE_CATALOGUE => ApiResponse::ok($this->catalogue->execute($request->principal)),
            self::RESOURCE_RULES => $this->rules($request),
            self::RESOURCE_RULE => $this->rule($request),
            self::RESOURCE_QUEUE => ApiResponse::ok($this->admin->listQueue(
                $request->principal,
                $this->optional($request->queryString('state')),
                $request->queryInt('rule_id', 0) > 0 ? $request->queryInt('rule_id', 0) : null,
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            )),
            self::RESOURCE_QUEUE_CANCEL => $this->idempotent->run(
                $request,
                'notification.queue.cancel',
                fn (): ApiResponse => ApiResponse::ok(
                    $this->admin->cancelQueued($request->principal, $request->intPathParam('id')),
                ),
            ),
            self::RESOURCE_POLICIES => $this->policies($request),
            self::RESOURCE_SUPPRESSIONS => $this->suppressions($request),
            self::RESOURCE_CONSENTS => ApiResponse::ok($this->admin->listConsents(
                $request->principal,
                $request->queryInt('user_id', 0) > 0 ? $request->queryInt('user_id', 0) : null,
                $this->optional($request->queryString('category_key')),
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            )),
            default => throw ApiProblem::notFound(),
        };
    }

    private function rules(ApiRequest $request): ApiResponse
    {
        if ($request->method === 'GET') {
            return ApiResponse::ok($this->admin->listRules(
                $request->principal,
                $this->optional($request->queryString('trigger_key')),
                $this->optional($request->queryString('state')),
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            ));
        }

        return $this->idempotent->run(
            $request,
            'notification.rule.create',
            fn (): ApiResponse => ApiResponse::created(
                $this->admin->saveRule($request->principal, null, $request->body),
            ),
        );
    }

    private function rule(ApiRequest $request): ApiResponse
    {
        $id = $request->intPathParam('id');

        if ($request->method === 'GET') {
            return ApiResponse::ok($this->admin->getRule($request->principal, $id));
        }

        if ($request->method === 'DELETE') {
            return $this->idempotent->run(
                $request,
                'notification.rule.delete',
                function () use ($request, $id): ApiResponse {
                    $this->admin->deleteRule($request->principal, $id);

                    return ApiResponse::noContent();
                },
            );
        }

        return $this->idempotent->run(
            $request,
            'notification.rule.update',
            fn (): ApiResponse => ApiResponse::ok(
                $this->admin->saveRule($request->principal, $id, $request->body),
            ),
        );
    }


    private function policies(ApiRequest $request): ApiResponse
    {
        if ($request->method === 'GET') {
            return ApiResponse::ok($this->admin->policyMatrix($request->principal));
        }

        return $this->idempotent->run(
            $request,
            'notification.policies.save',
            function () use ($request): ApiResponse {
                $cells = $request->body['cells'] ?? null;

                return ApiResponse::ok($this->admin->savePolicyMatrix(
                    $request->principal,
                    \is_array($cells) ? array_values($cells) : [],
                ));
            },
        );
    }

    private function suppressions(ApiRequest $request): ApiResponse
    {
        if ($request->method === 'GET') {
            return ApiResponse::ok($this->admin->listSuppressions(
                $request->principal,
                $this->optional($request->queryString('channel_key')),
                $this->optional($request->queryString('address')),
                max(1, $request->queryInt('page', 1)),
                $request->queryInt('per_page', self::DEFAULT_PER_PAGE),
            ));
        }

        return $this->idempotent->run(
            $request,
            'notification.suppression.save',
            fn (): ApiResponse => ApiResponse::ok(
                $this->admin->suppressAddress($request->principal, $request->body),
            ),
        );
    }

    private function optional(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
