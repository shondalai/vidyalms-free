<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Commerce;

use Shondalai\VidyaLMS\Core\Infrastructure\Persistence\Notification\SqlProviderSettingsRepository;
use Shondalai\VidyaLMS\Core\Port\Secrets\SecretsPort;

/**
 * Resolves stored credential references for AI and notification integrations.
 * Unknown, malformed, missing and disabled references resolve to null.
 * Callers must refuse operations when the required credential is unavailable.
 */
final class ProviderSecrets
{
    public function __construct(
        private readonly SqlProviderSettingsRepository $settings,
        private readonly SecretsPort $secrets,
    ) {
    }

    /**
     * The secret this provider signs or authenticates with, or null.
     *
     * @param array<string, int> $scopeIds scope type to id, for the scopes in play
     */
    public function for(string $registryKey, string $providerKey, array $scopeIds = []): ?string
    {
        if (!$this->settings->isEnabled($registryKey, $providerKey, $scopeIds)) {
            return null;
        }

        $ref = $this->settings->secretRef($registryKey, $providerKey, $scopeIds);

        if ($ref === null) {
            return null;
        }

        $value = $this->secrets->resolve($ref);

        return $value === null || $value === '' ? null : $value;
    }
}
