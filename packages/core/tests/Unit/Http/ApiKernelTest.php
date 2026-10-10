<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Http\ApiKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Route;

final class ApiKernelTest extends TestCase
{
    private function kernelWith(string $method, string $pattern, \Closure $body): ApiKernel
    {
        $kernel = new ApiKernel(new NullLogger());
        $kernel->register(new Route($method, $pattern, new class ($body) implements HandlerInterface {
            public function __construct(private readonly \Closure $body)
            {
            }

            public function handle(ApiRequest $request): ApiResponse
            {
                return ($this->body)($request);
            }
        }));

        return $kernel;
    }

    private function request(string $method, string $path, ?Principal $principal = null): ApiRequest
    {
        return new ApiRequest($method, $path, $principal ?? Principal::user(42));
    }

    public function testDispatchesToTheMatchingRoute(): void
    {
        $kernel = $this->kernelWith('GET', '/courses/{id}', static fn (ApiRequest $request): ApiResponse
            => ApiResponse::ok(['id' => $request->intPathParam('id')]));

        $response = $kernel->handle($this->request('GET', '/courses/7'));

        self::assertSame(200, $response->status);
        self::assertSame(['id' => 7], $response->data);
    }

    public function testUnknownPathIs404AndWrongMethodIs405(): void
    {
        $kernel = $this->kernelWith('GET', '/courses', static fn (): ApiResponse => ApiResponse::ok([]));

        self::assertSame(404, $kernel->handle($this->request('GET', '/nothing'))->status);
        self::assertSame(405, $kernel->handle($this->request('DELETE', '/courses'))->status);
    }

    public function testMapsDomainExceptionsOntoProblemResponses(): void
    {
        $cases = [
            [static fn () => throw ValidationException::forField('title', 'Required.'), 422],
            [static fn () => throw EntityNotFoundException::for('Course', 9), 404],
            [static fn () => throw new ConflictException('Already enrolled.'), 409],
            [static fn () => throw new \RuntimeException('secret internals'), 500],
        ];

        foreach ($cases as [$body, $expectedStatus]) {
            $kernel = $this->kernelWith('POST', '/x', $body);
            $response = $kernel->handle($this->request('POST', '/x'));

            self::assertSame($expectedStatus, $response->status);
            self::assertSame('application/problem+json', $response->headers['Content-Type'] ?? null);
        }
    }

    public function testInternalErrorsLeakNothing(): void
    {
        $kernel = $this->kernelWith('POST', '/x', static fn () => throw new \RuntimeException('db password is hunter2'));
        $response = $kernel->handle($this->request('POST', '/x'));

        self::assertSame(500, $response->status);
        self::assertStringNotContainsString('hunter2', json_encode($response->data, JSON_THROW_ON_ERROR));
    }

    public function testAuthorizationFailureIs401ForGuestsAnd403ForUsers(): void
    {
        $body = static fn () => throw NotAuthorizedException::forCapability('vidyalms.teach');

        $kernel = $this->kernelWith('POST', '/x', $body);

        self::assertSame(401, $kernel->handle($this->request('POST', '/x', Principal::guest()))->status);
        self::assertSame(403, $kernel->handle($this->request('POST', '/x', Principal::user(42)))->status);
    }

    public function testValidationProblemCarriesFieldErrors(): void
    {
        $kernel = $this->kernelWith('POST', '/x', static fn () => throw new ValidationException(['title' => 'Required.']));
        $response = $kernel->handle($this->request('POST', '/x'));

        self::assertSame(['title' => 'Required.'], $response->data['errors'] ?? null);
    }
}
