<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http;

use Psr\Log\LoggerInterface;
use Shondalai\VidyaLMS\Core\Domain\Shared\ConflictException;
use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;
use Shondalai\VidyaLMS\Core\Domain\Shared\EntityNotFoundException;
use Shondalai\VidyaLMS\Core\Domain\Shared\NotAuthorizedException;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * Dispatches neutral requests to handlers and maps every failure onto the
 * problem vocabulary of the contract. Both adapters route through this one
 * class, which is what keeps /v1 identical on Joomla and WordPress.
 */
final class ApiKernel
{
    /** @var list<Route> */
    private array $routes = [];

    /** @var array<string, true> */
    private array $claimed = [];

    /** @var list<Middleware> */
    private array $middleware = [];

    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Wraps every route of this kernel, outermost first in registration order.
     *
     * This is the seam a cross-cutting concern uses. Without it, a licence
     * check, an audit trail or a rate limit had to be added to every handler
     * by hand, which is seventy-one edits in the free build for one rule that
     * belongs to a package.
     *
     * A middleware registered after the kernel has answered requests applies
     * to the next one: the chain is built per request, not at boot, so a
     * package that installs late still lands.
     */
    public function use(Middleware $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function register(Route $route): void
    {
        $key = $route->method . ' ' . $route->pattern;

        if (isset($this->claimed[$key])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
            throw DuplicateRouteException::for($route->method, $route->pattern);
        }

        $this->claimed[$key] = true;
        $this->routes[] = $route;
    }

    /**
     * Every path template this kernel actually serves, once each and sorted.
     *
     * It is the answer to a question only the running kernel can answer: the
     * bundle and the host ship separately, and a paid package adds routes from
     * a listener, so which of the contract's paths exist on this install is
     * decided at boot rather than in openapi.yaml. An app given the list can
     * decline to draw a control that would 404, which is the failure the
     * workbench's export button already had: the operation was on the
     * contract, in the SDK and in the bundle, and answered 404 on every site
     * where the package serving it was not installed.
     *
     * It is a **discovery** list and never a permission list. What a caller
     * may do is still the answer to the request, rendered as a permission
     * state; this only says whether there is anything there to ask.
     *
     * The method is dropped because an app asks about a resource, not about a
     * verb: a path that exists at all is one whose control can be drawn, and
     * the 405 for a wrong method is a programming error rather than a state to
     * render.
     *
     * @return list<string>
     */
    public function servedTemplates(): array
    {
        $templates = [];

        foreach ($this->routes as $route) {
            $templates[$route->pattern] = true;
        }

        $sorted = array_keys($templates);
        sort($sorted);

        return $sorted;
    }

    /**
     * The one spelling of a path that the whole chain agrees on.
     *
     * {@see Route::match()} trims the outer slashes off before comparing, so
     * "//manage/organisations/1/members" dispatches to the organisation
     * members route. Every middleware above dispatch was testing the raw
     * string instead, with anchored prefixes and anchored patterns, so the
     * same request served the same rows and was covered by neither the tenancy
     * middleware nor the access log. Both shipped host mounts happen to
     * normalise on the way in, which is why it was not reachable through them
     * and was reachable through the core kernel, which is the boundary a third
     * host adapter, a CLI driver or a future mount is written against.
     *
     * Collapsing happens once, here, and everything above dispatch derives its
     * answer from the result rather than agreeing with Route by coincidence.
     */
    public static function canonicalPath(string $path): string
    {
        return '/' . trim($path, '/');
    }

    public function handle(ApiRequest $request): ApiResponse
    {
        $canonical = self::canonicalPath($request->path);
        $request = $canonical === $request->path ? $request : $request->withPath($canonical);

        try {
            return $this->through($request);
        } catch (ApiProblem $problem) {
            return $problem->toResponse();
        } catch (ValidationException $exception) {
            return ApiProblem::validation($exception->errors)->toResponse();
        } catch (NotAuthorizedException $exception) {
            if ($request->principal->isGuest()) {
                return ApiProblem::unauthorized()->toResponse();
            }

            return ApiProblem::forbidden($exception->getMessage())->toResponse();
        } catch (EntityNotFoundException $exception) {
            return ApiProblem::notFound($exception->getMessage())->toResponse();
        } catch (ConflictException $exception) {
            // The refusal's own extensions travel with it, so a cooldown
            // answers when the learner may try again rather than making the
            // client poll for it.
            return (new ApiProblem(409, 'Conflict', $exception->getMessage(), $exception->extensions))->toResponse();
        } catch (DomainException $exception) {
            return ApiProblem::badRequest($exception->getMessage())->toResponse();
        } catch (\Throwable $failure) {
            $this->logger->error('VidyaLMS API failure.', [
                'method' => $request->method,
                'path' => $request->path,
                'exception' => $failure,
            ]);

            return ApiProblem::internal()->toResponse();
        }
    }

    /**
     * The middleware chain around dispatch. Built here rather than at boot so
     * a middleware registered after the first request still applies, and
     * inside the try above so a middleware that throws maps onto the same
     * problem vocabulary as a handler that throws.
     */
    private function through(ApiRequest $request): ApiResponse
    {
        $next = $this->dispatch(...);

        foreach (array_reverse($this->middleware) as $middleware) {
            $inner = $next;
            $next = static fn (ApiRequest $passed): ApiResponse => $middleware->process($passed, $inner);
        }

        return $next($request);
    }

    private function dispatch(ApiRequest $request): ApiResponse
    {
        $pathExists = false;

        foreach ($this->routes as $route) {
            $params = $route->match($request->method, $request->path);

            if ($params !== null) {
                return $route->handler->handle($request->withPathParams($params));
            }

            if (!$pathExists && $route->match($route->method, $request->path) !== null) {
                $pathExists = true;
            }
        }

        if ($pathExists) {
            return (new ApiProblem(405, 'Method not allowed'))->toResponse();
        }

        return ApiProblem::notFound()->toResponse();
    }
}
