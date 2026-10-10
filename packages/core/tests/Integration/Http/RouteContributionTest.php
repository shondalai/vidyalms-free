<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\ApiResponse;
use Shondalai\VidyaLMS\Core\Http\DuplicateRouteException;
use Shondalai\VidyaLMS\Core\Http\HandlerInterface;
use Shondalai\VidyaLMS\Core\Http\Route;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * The seam a Pro package adds routes through. It exists so no package ever
 * has a reason to edit CoreKernel: the free build's bytes are the same
 * whether or not Pro is installed, and a package that boots after the kernel
 * was first asked for still lands its routes.
 */
final class RouteContributionTest extends TestCase
{
    private TestPorts $env;
    private CoreKernel $kernel;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
        $this->kernel = new CoreKernel($this->env->ports);
    }

    public function testRoutesContributedBeforeTheKernelIsBuiltAreServed(): void
    {
        $this->kernel->addRoutes(new Route('GET', '/groups', $this->handler('groups')));

        $response = $this->kernel->apiKernel()->handle(new ApiRequest('GET', '/groups', Principal::guest()));

        self::assertSame(200, $response->status);
        self::assertSame(['served' => 'groups'], $response->data);
    }

    public function testRoutesContributedAfterTheKernelIsBuiltAreServedToo(): void
    {
        $api = $this->kernel->apiKernel();

        self::assertSame(404, $api->handle(new ApiRequest('GET', '/webhooks', Principal::guest()))->status);

        $this->kernel->addRoutes(new Route('GET', '/webhooks', $this->handler('webhooks')));

        $response = $api->handle(new ApiRequest('GET', '/webhooks', Principal::guest()));

        self::assertSame(200, $response->status);
        self::assertSame(['served' => 'webhooks'], $response->data);
    }

    public function testSeveralRoutesLandInOneCall(): void
    {
        $this->kernel->addRoutes(
            new Route('GET', '/groups', $this->handler('list')),
            new Route('POST', '/groups', $this->handler('create')),
        );

        $api = $this->kernel->apiKernel();

        self::assertSame(200, $api->handle(new ApiRequest('GET', '/groups', Principal::guest()))->status);
        self::assertSame(200, $api->handle(new ApiRequest('POST', '/groups', Principal::guest()))->status);
    }

    public function testAContributionThatCollidesWithACoreRouteThrows(): void
    {
        $this->kernel->addRoutes(new Route('GET', '/manage/courses', $this->handler('hijack')));

        $this->expectException(DuplicateRouteException::class);
        $this->expectExceptionMessage('GET /manage/courses');

        $this->kernel->apiKernel();
    }

    public function testTwoContributionsForTheSameMethodAndPatternThrow(): void
    {
        $this->kernel->addRoutes(new Route('GET', '/groups', $this->handler('first')));
        $this->kernel->apiKernel();

        $this->expectException(DuplicateRouteException::class);

        $this->kernel->addRoutes(new Route('GET', '/groups', $this->handler('second')));
    }

    public function testTheSamePatternUnderAnotherMethodIsNotADuplicate(): void
    {
        $this->kernel->addRoutes(
            new Route('GET', '/groups', $this->handler('read')),
            new Route('DELETE', '/groups', $this->handler('delete')),
        );

        $api = $this->kernel->apiKernel();

        self::assertSame(['served' => 'read'], $api->handle(new ApiRequest('GET', '/groups', Principal::guest()))->data);
        self::assertSame(['served' => 'delete'], $api->handle(new ApiRequest('DELETE', '/groups', Principal::guest()))->data);
    }

    public function testTheCoreRoutesStillAnswerBesideAContribution(): void
    {
        $this->kernel->addRoutes(new Route('GET', '/groups', $this->handler('groups')));

        $response = $this->kernel->apiKernel()->handle(new ApiRequest('GET', '/courses', Principal::guest()));

        self::assertSame(200, $response->status);
    }

    private function handler(string $name): HandlerInterface
    {
        return new class ($name) implements HandlerInterface {
            public function __construct(private readonly string $name)
            {
            }

            public function handle(ApiRequest $request): ApiResponse
            {
                return ApiResponse::ok(['served' => $this->name]);
            }
        };
    }
}
