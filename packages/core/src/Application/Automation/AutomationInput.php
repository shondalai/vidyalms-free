<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Automation;

use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;

/**
 * The allowlist every automation write passes through.
 *
 * A field the operation does not name is refused by name rather than dropped
 * with a 200. An author who mistypes a key otherwise gets a success and a rule
 * that does not do what they wrote, which is the failure a silent drop always
 * is: the write reports that it stored something it did not store.
 */
final class AutomationInput
{
    /**
     * @param array<string, mixed> $input
     * @param list<string>         $keys
     */
    public static function only(array $input, array $keys): void
    {
        foreach (array_keys($input) as $key) {
            if (!\in_array($key, $keys, true)) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception data; JSON encoding and HTML escaping happen at the host output boundary.
                throw ValidationException::forField((string) $key, 'This field is not supported by this operation.');
            }
        }
    }
}
