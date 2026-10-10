<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry;

/**
 * Raised when replace() targets a key nothing has registered. That is a boot
 * ordering mistake in the package doing the override, not a runtime condition:
 * defaults are registered before any add-on boots, so a missing key means the
 * override ran too early or spelled the key wrong.
 *
 * Deliberately not UnknownProviderException, which is a domain condition about
 * stored rows referencing a provider that is not installed and drives the
 * "needs VidyaLMS Pro" placeholder. Different layer, different audience.
 */
final class ProviderNotRegisteredException extends \LogicException
{
    public static function forKey(string $key, string $registry): self
    {
        return new self(sprintf('No provider "%s" is registered in %s, so there is nothing to replace.', $key, $registry));
    }
}
