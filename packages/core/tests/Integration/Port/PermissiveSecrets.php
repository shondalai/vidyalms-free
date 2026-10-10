<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Tests\Integration\Port;

use Shondalai\VidyaLMS\Core\Port\Secrets\SecretsPort;

/**
 * A store that resolves whatever it is handed, which is the one thing the
 * secrets grammar forbids.
 *
 * Without the rule a site owner can paste the secret itself into
 * `provider_settings.secret_ref`, and it would work, which is worse than it
 * failing. This fixture is what proves the contract notices.
 */
final class PermissiveSecrets implements SecretsPort
{
    public function resolve(string $ref): ?string
    {
        return $ref === '' ? null : $ref;
    }
}
