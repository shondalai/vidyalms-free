<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Shared\IdempotencyStore;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiProblem;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/**
 * Replay protection for mutating handlers. When the request carries an
 * Idempotency-Key, the first execution stores its response under a scope of
 * route name plus principal id; a replay returns the stored response with the
 * same status and never re-runs the operation. Failures release the claim so
 * the client may retry with the same key.
 *
 * **A stored outcome is only ever replayed for the same request.** Both
 * families compute one canonical fingerprint over the request's method, its
 * matched path and the handler's meaningful input, store it beside the
 * outcome and compare it on every replay, so a key reused for anything else
 * is answered 422 rather than handed somebody else's response. The fingerprint
 * carries the path because the scope cannot: `idempotency_keys.scope` is 100
 * bytes and the notification-studio routes already spell a 98-byte scope for a
 * 19-digit principal, so a path digest in the scope would either overflow the
 * column or truncate a route name, and truncating a scope is how two
 * principals come to share one. Until this was written, `run()` computed no
 * fingerprint at all: one key would replay across every resource on the same
 * route, so a second POST to a different course answered 201 carrying the
 * first course's row and wrote nothing, and a changed body under a spent key
 * was replayed rather than refused.
 */
final class IdempotentExecution
{
    /**
     * Matches the idempotency_keys.idem_key column and the maxLength the
     * contract declares for the Idempotency-Key header. An oversized key must
     * fail as a 422 here, not as a driver error deep in the store.
     */
    private const MAX_KEY_BYTES = 190;
    private const MAX_SCOPE_BYTES = 100;

    /**
     * The problem extension that names a reused key whose request input
     * changed, so a client branches on a stable token rather than on prose.
     */
    public const REASON_INPUT_MISMATCH = 'idempotency.input_mismatch';

    public function __construct(
        private readonly IdempotencyStore $store,
    ) {
    }

    /**
     * @param callable(array<string, mixed>): ApiResponse|null $onReplay Re-read sensitive resources with current authorization.
     * @param callable(): ApiResponse   $operation
     * @param array<string, mixed>|null $input The handler's meaningful input,
     *     when host routing/auth query parameters must not enter the digest.
     *     Absent, the request's own body and query are digested, and the
     *     method and matched path are digested either way.
     */
    public function run(
        ApiRequest $request,
        string $routeName,
        callable $operation,
        ?array $input = null,
        ?callable $onReplay = null,
    ): ApiResponse {
        $key = $request->idempotencyKey;

        if ($key === null || trim($key) === '') {
            return $operation();
        }

        $this->assertKeyLength($key);

        $scope = $routeName . ':' . $request->principal->userId;
        $fingerprint = self::fingerprint($request, $input);
        $stored = $this->store->claimOrReplay($scope, $key);

        if ($stored !== null) {
            self::assertSameRequest($stored, $fingerprint);

            if ($onReplay !== null && (int) ($stored['status'] ?? 200) < 400) {
                $fresh = $onReplay(is_array($stored['data'] ?? null) ? $stored['data'] : []);
                return new ApiResponse($fresh->status, $fresh->data, $fresh->headers + ['Idempotent-Replayed' => 'true']);
            }

            return $this->replay($stored);
        }

        try {
            $response = $operation();
        } catch (\Throwable $failure) {
            $this->store->release($scope, $key);

            throw $failure;
        }

        $this->store->complete($scope, $key, [
            'status' => $response->status,
            'data' => $response->data,
            'request_fingerprint' => $fingerprint,
        ]);

        return $response;
    }

    /**
     * A required-key mutation whose claim, aggregate writes and completed
     * response commit together. The supplied database MUST be the same port
     * instance used by this helper's store; a separately connected store
     * cannot participate in the transaction. Aggregate operations may nest
     * their own transactions on that same port.
     *
     * The handler authorizes before entering, including on replay. This is
     * not an authorization boundary. On failure the transaction rolls back
     * the claim together with the mutation; a separate release would be an
     * unnecessary write and could mask the original error.
     *
     * @param callable(): ApiResponse $operation
     * @param array<string, mixed>|null $input The handler's meaningful input,
     *     when host routing/auth query parameters must not enter the digest.
     */
    public function runAtomic(
        ApiRequest $request,
        string $routeName,
        DatabasePort $database,
        callable $operation,
        ?array $input = null,
    ): ApiResponse {
        $key = $request->idempotencyKey;

        // A required header that is absent is a malformed request, not a
        // rejected body: the IETF idempotency-key draft answers 400 here, and
        // the distinction is what tells a client to fix its transport rather
        // than its payload. An oversized key stays a 422 below, because the
        // header is present and its value is what fails validation.
        if ($key === null || trim($key) === '') {
            throw ApiProblem::badRequest('A non-empty Idempotency-Key header is required for this operation.');
        }

        $this->assertKeyLength($key);
        $scope = $routeName . ':' . $request->principal->userId;

        // Route names are internal identifiers, not user input. Fail a bad
        // caller contract rather than truncating either the route or actor
        // and accidentally sharing a replay scope between two principals.
        if (trim($routeName) === '' || \strlen($scope) > self::MAX_SCOPE_BYTES) {
            throw new \InvalidArgumentException(
                'Atomic idempotency needs a non-empty route and a route:principal scope of at most 100 bytes.',
            );
        }

        $fingerprint = self::fingerprint($request, $input);

        return $database->transactional(function () use ($scope, $key, $operation, $fingerprint): ApiResponse {
            $stored = $this->store->claimOrReplay($scope, $key);

            if ($stored !== null) {
                self::assertSameRequest($stored, $fingerprint);

                return $this->replay($stored);
            }

            $response = $operation();
            $this->store->complete($scope, $key, [
                'status' => $response->status,
                'data' => $response->data,
                'request_fingerprint' => $fingerprint,
            ]);

            return $response;
        });
    }

    /**
     * The canonical digest of one request, shared by both families.
     *
     * A handler that declares no input gets the method and the matched path
     * inside its digest, because a path parameter reaches neither the body nor
     * the query, so a route whose scope names no resource would otherwise
     * replay one resource's answer for another. That is the whole of the
     * defect this method closes, and it is closed for every such caller of
     * both families at once rather than route by route.
     *
     * **A handler that declares its input owns its identity and the raw path
     * is left out of its digest**, because the two are alternatives rather
     * than a belt and braces. The notification studio is the shipped case:
     * `/fr-ca/` and `/fr-CA/` are one translation under two spellings, its
     * scope already carries the canonical `key|locale|channel` digest, and
     * folding the raw path in would answer the second spelling with a 422 for
     * a request that is the same request.
     *
     * @param array<string, mixed>|null $input
     */
    private static function fingerprint(ApiRequest $request, ?array $input): string
    {
        if ($input !== null) {
            return hash('sha256', json_encode(self::canonicalInput($input), JSON_THROW_ON_ERROR));
        }

        return hash('sha256', json_encode(
            [
                'route' => $request->method . ' ' . $request->path,
                'input' => self::canonicalInput(
                    ['body' => $request->body, 'query' => $request->query],
                ),
            ],
            JSON_THROW_ON_ERROR,
        ));
    }

    /**
     * Unprocessable, not a conflict. A conflict invites the client to retry
     * once the contended state settles, and this request will never be
     * accepted under this key however long it waits. The draft says 422 for
     * exactly that reason, and the reason extension stays so a client already
     * branching on it keeps working. A stored outcome carrying no fingerprint
     * at all is treated as a mismatch: it was written before this check
     * existed, and answering it as a replay is the failure being fixed.
     *
     * @param array<string, mixed> $stored
     */
    private static function assertSameRequest(array $stored, string $fingerprint): void
    {
        if (
            \is_string($stored['request_fingerprint'] ?? null)
            && hash_equals($stored['request_fingerprint'], $fingerprint)
        ) {
            return;
        }

        throw new ApiProblem(
            422,
            'Validation failed',
            'This idempotency key belongs to different request input. Use a new key for a new edit.',
            [
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                'reason' => self::REASON_INPUT_MISMATCH,
                'errors' => [
                    'Idempotency-Key' => 'This key was used for different request input.',
                ],
            ],
        );
    }

    /** Map order is irrelevant; list order and scalar types are significant. */
    private static function canonicalInput(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(self::canonicalInput(...), $value);
    }

    private function assertKeyLength(string $key): void
    {
        if (\strlen($key) > self::MAX_KEY_BYTES) {
            throw ValidationException::forField(
                'Idempotency-Key',
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                sprintf('May not exceed %d bytes.', self::MAX_KEY_BYTES),
            );
        }
    }

    /**
     * @param array<string, mixed> $stored
     */
    private function replay(array $stored): ApiResponse
    {
        $status = \is_int($stored['status'] ?? null) ? $stored['status'] : 200;
        $data = $stored['data'] ?? null;

        /** @var array<string, mixed>|null $data */
        $data = \is_array($data) ? $data : null;

        return new ApiResponse($status, $data, ['Idempotent-Replayed' => 'true']);
    }
}
