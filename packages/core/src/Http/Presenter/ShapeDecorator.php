<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http\Presenter;

/**
 * One package's addition to one emitted shape.
 *
 * This is how a field reaches a management response without the free build
 * changing. The first thing Pro needs is an extra key on the course row
 * (seats_used, a group id, a licence flag), and before this existed that
 * meant editing a final class with a private constructor that forty-two
 * handlers called statically by name: not decoratable, not subclassable, not
 * injectable, and the route seam is additive only, so replacing the route was
 * no way round it either.
 *
 * A decorator is asked about a shape by its stable name (the SHAPE_*
 * constants on the presenters), receives what the free build emitted and the
 * object it came from, and answers the array to emit. Decorators run in
 * registration order, each seeing the previous one's output.
 *
 * Two rules, and both matter:
 *
 * - A decorator adds. Removing or rewriting a field the contract declares
 *   breaks a client that read openapi.yaml, and nothing here can stop that,
 *   so a package that does it owns the consequences.
 * - A decorator that throws takes the request down. It runs inside the
 *   response, not beside it, so there is nothing sensible to fall back to:
 *   half a course row is worse than a refusal that names the package.
 */
interface ShapeDecorator
{
    public function supports(string $shape): bool;

    /**
     * @param array<string, mixed> $emitted what the free build produced
     * @param object               $source  the object it was produced from
     *
     * @return array<string, mixed>
     */
    public function decorate(array $emitted, object $source): array;
}
