<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry;

final class DuplicateProviderException extends \LogicException
{
    public static function forKey(string $key, string $registry): self
    {
        return new self(sprintf('A provider "%s" is already registered in %s.', $key, $registry));
    }
}
