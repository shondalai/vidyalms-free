<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Port\Secrets;

/**
 * The shipped default: nothing resolves.
 *
 * An adapter that has not implemented SecretsPort gets this, so a build whose
 * host cannot supply a secret refuses to create a session and refuses to
 * verify a delivery, rather than doing either against nothing. That is the
 * fail-closed direction stated on the port, expressed as the default rather
 * than as a rule an adapter is asked to remember.
 */
final class NoSecrets implements SecretsPort
{
    public function resolve(string $ref): ?string
    {
        return null;
    }
}
