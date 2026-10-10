<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

final class NotAuthorizedException extends DomainException
{
    public static function forCapability(string $capability): self
    {
        return new self(sprintf('The current user may not perform "%s".', $capability));
    }
}
