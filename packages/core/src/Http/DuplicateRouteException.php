<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Http;

/**
 * Two routes claimed the same method and pattern.
 *
 * Registration is strict for the same reason the provider registries are: a
 * silent second registration means whichever package booted first decides the
 * behaviour of an endpoint, and that is a bug that only shows up on the site
 * where the boot order differs. Failing at boot names both packages instead.
 */
final class DuplicateRouteException extends \LogicException
{
    public static function for(string $method, string $pattern): self
    {
        return new self(sprintf('A route for %s %s is already registered.', $method, $pattern));
    }
}
