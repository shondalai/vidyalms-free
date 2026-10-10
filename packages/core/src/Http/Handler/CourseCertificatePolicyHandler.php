<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Handler;

use Shondalai\VidyaLMS\Core\Application\Credentials\CourseCertificatePolicy;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Port\Database\DatabasePort;

final class CourseCertificatePolicyHandler implements HandlerInterface
{
    public function __construct(
        private readonly CourseCertificatePolicy $policy,
        private readonly IdempotentExecution $idempotent,
        private readonly DatabasePort $database,
        private readonly bool $picker = false,
    ) {
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $id = $request->intPathParam('id');
        $this->policy->authorize($request->principal, $id);
        if ($this->picker) {
            return ApiResponse::ok($this->policy->picker(
                $request->principal,
                $id,
                $request->queryString('search'),
                $request->queryInt('page', 1),
                $request->queryInt('per_page', 20)
            ));
        }
        if ($request->method === 'GET') {
            return ApiResponse::ok($this->policy->get($request->principal, $id));
        }
        try {
            return $this->idempotent->runAtomic(
                $request,
                'certificate-policy:' . $id,
                $this->database,
                fn (): ApiResponse => ApiResponse::ok($this->policy->put($request->principal, $id, $request->body)),
                ['body' => $request->body]
            );
        } catch (\Shondalai\VidyaLMS\Core\Domain\Shared\DomainException | \Shondalai\VidyaLMS\Core\Http\ApiProblem $failure) {
            // A problem the envelope raised (a missing or reused
            // Idempotency-Key) already carries its own status. Letting it
            // fall through to the salvage branch below rewrote a 400 into a
            // 409 whenever the row happened to have moved on.
            throw $failure;
        } catch (\Throwable $failure) {
            $expected = $request->body['expected_revision'] ?? null;
            if (is_int($expected) && $this->policy->hasChanged($id, $expected)) {
                throw new \Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException(
                    'This course certificate policy changed during the save.',
                );
            }
            throw $failure;
        }
    }
}
