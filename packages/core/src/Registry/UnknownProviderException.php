<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry;

use Shondalai\VidyaLMS\Core\Domain\Shared\DomainException;

/**
 * Raised when stored data references a provider that is not registered, for
 * example a Pro lesson type after Pro was removed. Callers that render
 * content catch this and show a "needs VidyaLMS Pro" placeholder instead of
 * breaking the course.
 */
final class UnknownProviderException extends DomainException
{
    public static function forKey(string $key, string $registry): self
    {
        return new self(sprintf('No provider "%s" is registered in %s.', $key, $registry));
    }
}
