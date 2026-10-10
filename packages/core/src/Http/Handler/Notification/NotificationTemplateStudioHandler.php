<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler\Notification;

use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateStudio;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateLocale;
use Shondalai\VidyaLMS\Core\Application\Notification\NotificationTemplateTestDelivery;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\Handler\IdempotentExecution;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;

/** Transport only; draft safety and compilation live in the application service. */
final class NotificationTemplateStudioHandler implements HandlerInterface
{
    /**
     * Idempotency scope is VARCHAR(100). The execution wrapper appends a
     * colon and the principal id, which can occupy 20 bytes on a signed
     * 64-bit build. Keep the route-owned portion at no more than 80 bytes.
     */
    private const IDEMPOTENCY_SCOPE_PREFIX = 'nt.';

    public const LIST = 'list';
    public const DETAIL = 'detail';
    public const DRAFT = 'draft';
    public const PUBLISH = 'publish';
    public const PREVIEW = 'preview';
    public const TEST_SEND = 'test_send';

    public function __construct(
        private readonly string $resource,
        private readonly NotificationTemplateStudio $studio,
        private readonly IdempotentExecution $idempotent,
        private readonly ?NotificationTemplateTestDelivery $testDelivery = null,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        // Authorization precedes replay so a revoked manager cannot replay private content.
        $this->studio->authorize($request->principal);
        if ($this->resource === self::LIST) {
            return ApiResponse::ok($this->studio->list(
                $request->principal,
                $request->query,
                $request->queryInt('page', 1),
                $request->queryInt('per_page', 25),
            ));
        }
        $key = $request->pathParam('template_key');
        $locale = NotificationTemplateLocale::canonicalize($request->pathParam('locale'));
        $channel = $request->pathParam('channel_key');
        if ($this->resource === self::DETAIL) {
            return ApiResponse::ok($this->studio->detail($request->principal, $key, $locale, $channel));
        }
        if ($this->resource === self::PREVIEW) {
            $this->fields($request->body, ['source']);
            return ApiResponse::ok($this->studio->preview(
                $request->principal,
                $key,
                $locale,
                $channel,
                $this->source($request),
            ));
        }
        if ($this->resource === self::TEST_SEND) {
            if ($this->testDelivery === null) {
                throw ApiProblem::notFound();
            }
            $this->fields($request->body, ['source']);
            if ($request->idempotencyKey === null || trim($request->idempotencyKey) === '') {
                throw ValidationException::forField('Idempotency-Key', 'A unique key is required for test delivery.');
            }
            return $this->idempotent->run(
                $request,
                self::IDEMPOTENCY_SCOPE_PREFIX . 'test:'
                    . hash('sha256', $key . '|' . $locale . '|' . $channel),
                fn (): ApiResponse => ApiResponse::ok($this->testDelivery->send(
                    $request->principal,
                    $key,
                    $locale,
                    $channel,
                    $this->source($request),
                ) + $this->studio->deliveryReadiness()),
                // The scope above already names the canonical translation, so
                // the digest declares the body and query and leaves the raw
                // path out: /fr-ca/ and /fr-CA/ are one translation under two
                // spellings and must replay rather than answer 422.
                ['body' => $request->body, 'query' => $request->query],
            );
        }
        if (!\in_array($this->resource, [self::DRAFT, self::PUBLISH], true)) {
            throw ApiProblem::notFound();
        }
        // Scope the replay to the exact translation and operation, not only the collection.
        $scope = self::IDEMPOTENCY_SCOPE_PREFIX . $this->resource . '.' . strtolower($request->method)
            . ':' . hash('sha256', $key . '|' . $locale . '|' . $channel);
        return $this->idempotent->run($request, $scope, function () use ($request, $key, $locale, $channel): ApiResponse {
            if ($this->resource === self::PUBLISH) {
                $this->fields($request->body, ['expected_revision']);
                return ApiResponse::ok($this->studio->publish(
                    $request->principal,
                    $key,
                    $locale,
                    $channel,
                    $this->revision($request),
                ));
            }
            if ($request->method === 'DELETE') {
                $this->fields($request->body, []);
                $revision = $request->queryString('expected_revision');
                if (preg_match('/^[0-9]{1,9}$/D', $revision) !== 1) {
                    throw ValidationException::forField('expected_revision', 'A non-negative revision is required.');
                }
                return ApiResponse::ok($this->studio->discardDraft(
                    $request->principal,
                    $key,
                    $locale,
                    $channel,
                    (int) $revision,
                ));
            }
            $this->fields($request->body, ['source', 'expected_revision']);
            return ApiResponse::ok($this->studio->saveDraft(
                $request->principal,
                $key,
                $locale,
                $channel,
                $this->source($request),
                $this->revision($request),
            ));
        }, ['body' => $request->body, 'query' => $request->query]);
    }

    /** @return array<string, mixed> */
    private function source(ApiRequest $request): array
    {
        $source = $request->body['source'] ?? null;
        if (!\is_array($source) || array_is_list($source)) {
            throw ValidationException::forField('source', 'A structured template source is required.');
        }
        return $source;
    }

    private function revision(ApiRequest $request): int
    {
        $revision = $request->body['expected_revision'] ?? null;
        if (!\is_int($revision) || $revision < 0 || $revision > 999999999) {
            throw ValidationException::forField('expected_revision', 'A non-negative revision is required.');
        }
        return $revision;
    }

    /** @param array<string, mixed> $body
     * @param list<string> $allowed
     */
    private function fields(array $body, array $allowed): void
    {
        $unknown = array_diff(array_keys($body), $allowed);
        if ($unknown !== []) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw new ValidationException(array_fill_keys($unknown, 'This field is not accepted.'));
        }
    }
}
