<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Mail;

/**
 * What a host does with a non-scalar mail parameter.
 *
 * MailMessage::$parameters is JSON-safe rather than scalar, because a digest
 * carries a list of items. A {placeholder} substituter cannot render a list,
 * and the two adapters left to answer that themselves would answer it
 * differently: one would print the word Array, the other would drop the key,
 * and the same message would then differ between hosts, which is the one thing
 * this repository does not permit.
 *
 * So the decision is taken once, here, and both adapters call it: a non-scalar
 * value is not a placeholder value and is left out. A host template layer that
 * genuinely wants the structure reads $parameters directly.
 */
final class MailParameters
{
    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, scalar|null>
     */
    public static function scalar(array $parameters): array
    {
        $scalar = [];

        foreach ($parameters as $name => $value) {
            if ($value === null || \is_scalar($value)) {
                $scalar[(string) $name] = $value;
            }
        }

        return $scalar;
    }
}
