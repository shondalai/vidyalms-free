<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry;

/**
 * Raised when a provider is replaced by the instance already registered under
 * its key, which means the same override was installed twice. It is a boot
 * ordering mistake, never a runtime condition, so it extends LogicException
 * like the other registry programming errors.
 */
final class ProviderAlreadyInstalledException extends \LogicException
{
    public static function forKey(string $key, string $registry): self
    {
        return new self(sprintf(
            'The provider already registered under "%s" in %s cannot replace itself.',
            $key,
            $registry,
        ));
    }
}
