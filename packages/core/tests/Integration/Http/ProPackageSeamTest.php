<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Http;

use PHPUnit\Framework\TestCase;
use Shondalai\VidyaLMS\Core\Application\Shared\Principal;
use Shondalai\VidyaLMS\Core\Boot\CoreKernel;
use Shondalai\VidyaLMS\Core\Boot\Repositories;
use Shondalai\VidyaLMS\Core\Http\ApiRequest;
use Shondalai\VidyaLMS\Core\Http\Route;
use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Curriculum\DatabaseCourseRepository;
use Shondalai\VidyaLMS\Core\Tests\Support\Pro\AuditTrail;
use Shondalai\VidyaLMS\Core\Tests\Support\Pro\Exploding;
use Shondalai\VidyaLMS\Core\Tests\Support\Pro\PretendGroupScopedCourses;
use Shondalai\VidyaLMS\Core\Tests\Support\Pro\Recording;
use Shondalai\VidyaLMS\Core\Tests\Support\Pro\RefusingLicence;
use Shondalai\VidyaLMS\Core\Tests\Support\Pro\SeatSweepTask;
use Shondalai\VidyaLMS\Core\Tests\Support\Pro\SeatsHandler;
use Shondalai\VidyaLMS\Core\Tests\Support\TestPorts;

/**
 * One throwaway package shaped like Pro, exercising every seam the core
 * offers at once: a route, a provider, a cross-cutting middleware and a
 * repository of its own.
 *
 * It exists because a seam nobody has used is a seam that does not work.
 * packages/pro-core holds one README, so until this test none of these had
 * ever been driven by a second package: the route hook had a test, and the
 * other three had nothing at all.
 */
final class ProPackageSeamTest extends TestCase
{
    private TestPorts $env;

    protected function setUp(): void
    {
        $this->env = TestPorts::create();
    }

    public function testAPackageAddsARouteAProviderAMiddlewareAndARepositoryWithoutEditingTheCore(): void
    {
        $audit = new AuditTrail();

        $kernel = new CoreKernel(
            $this->env->ports,
            [],
            // A repository of its own, which every use case above it goes on
            // using through the interface.
            new Repositories(
                courses: new PretendGroupScopedCourses(
                    new DatabaseCourseRepository($this->env->database, $this->env->clock),
                ),
            ),
        );

        // A provider of its own, in the registry the free build fills.
        $kernel->registries->scheduledTasks->register(new SeatSweepTask());

        // A route of its own, through the documented hook.
        $kernel->addRoutes(new Route('GET', '/groups', new SeatsHandler()));

        $api = $kernel->apiKernel();

        // One cross-cutting concern, registered once rather than added to
        // seventy-one handlers.
        $api->use($audit);

        $contributed = $api->handle(new ApiRequest('GET', '/groups', Principal::guest()));
        self::assertSame(200, $contributed->status);
        self::assertSame(['seats' => 12], $contributed->data);

        $free = $api->handle(new ApiRequest('GET', '/courses', Principal::guest()));
        self::assertSame(200, $free->status);

        self::assertSame(
            ['GET /groups', 'GET /courses'],
            $audit->seen,
            'the middleware wraps a contributed route and a free one alike',
        );

        self::assertTrue($kernel->registries->scheduledTasks->has(SeatSweepTask::KEY));
        self::assertSame(
            'seats swept',
            $kernel->runScheduledTask(SeatSweepTask::KEY, 1, [], Principal::guest()),
            'both hosts run a type the package queued, with neither adapter changed',
        );

        // Observed through a use case rather than through the private
        // property: what matters is that the whole stack above it is using
        // the package's repository, not that the kernel stored it.
        self::assertSame(
            [],
            $kernel->getCatalogue->execute(Principal::guest())->items,
            'the package repository answered, and it answers nothing here',
        );
        self::assertGreaterThan(
            0,
            PretendGroupScopedCourses::$catalogueCalls,
            'the free catalogue route and the use case both went through the package repository',
        );
    }

    public function testAMiddlewareMayRefuseWithoutReachingTheHandler(): void
    {
        $kernel = new CoreKernel($this->env->ports);
        $api = $kernel->apiKernel();
        $api->use(new RefusingLicence());

        $response = $api->handle(new ApiRequest('GET', '/courses', Principal::guest()));

        self::assertSame(402, $response->status, 'a licence check answers on its own');
    }

    public function testMiddlewareRunsOutermostFirstInRegistrationOrder(): void
    {
        $kernel = new CoreKernel($this->env->ports);
        $api = $kernel->apiKernel();
        $first = new Recording('first');
        $second = new Recording('second');
        $api->use($first);
        $api->use($second);

        $api->handle(new ApiRequest('GET', '/courses', Principal::guest()));

        self::assertSame(['first in', 'first out'], $first->order);
        self::assertSame(['second in', 'second out'], $second->order);
        self::assertLessThan(
            $second->startedAt,
            $first->startedAt,
            'the first registered middleware is the outermost',
        );
    }

    public function testAMiddlewareThatThrowsIsAProblemLikeAnyOtherFailure(): void
    {
        $kernel = new CoreKernel($this->env->ports);
        $api = $kernel->apiKernel();
        $api->use(new Exploding());

        $response = $api->handle(new ApiRequest('GET', '/courses', Principal::guest()));

        self::assertSame(500, $response->status, 'the kernel still owns the problem vocabulary');
    }
}
