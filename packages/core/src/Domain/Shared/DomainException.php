<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Domain\Shared;

/**
 * Base class for every rule violation the domain can signal. The HTTP layer
 * maps subclasses to problem responses; nothing else may leak out of a use
 * case as an error.
 */
abstract class DomainException extends \RuntimeException
{
}
