<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Shared\AccessGuard;
use Shondalai\VidyaLMS\Core\Application\Shared\Capability;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

/** One authorization and atomic replay boundary for certificate authoring. */
final class CertificateTemplateWrite
{
    public function __construct(
        private readonly IdempotentExecution $idempotent,
        private readonly DatabasePort $database,
        private readonly AccessGuard $guard,
    ) {
    }

    /** @param callable(): ApiResponse $operation */
    public function run(ApiRequest $request, string $resource, callable $operation): ApiResponse
    {
        // A revoked manager cannot retrieve a previously successful private
        // response. The resource and method are scoped before replay; one
        // logical key cannot answer for another page, element or operation.
        $this->guard->require($request->principal, Capability::MANAGE);
        $scope = 'ct.' . hash('sha256', $request->method . ':' . $resource);

        return $this->idempotent->runAtomic($request, $scope, $this->database, $operation, [
            'body' => $request->body,
            // CMS route names, sessions and CSRF query parameters are not
            // certificate input. Only DELETE consumes this query value.
            'expected_revision' => $request->method === 'DELETE'
                ? $request->queryString('expected_revision')
                : null,
        ]);
    }

    public static function deleteRevision(ApiRequest $request): int
    {
        $value = $request->queryString('expected_revision');

        if (preg_match('/^[1-9][0-9]{0,9}$/D', $value) !== 1 || (int) $value > 2147483646) {
            throw ValidationException::forField('expected_revision', 'A positive template revision is required.');
        }

        return (int) $value;
    }
}
