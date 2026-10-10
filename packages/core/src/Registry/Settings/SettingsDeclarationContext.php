<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Settings;

/**
 * What a provider is told when it is asked to describe its own settings.
 *
 * A context object rather than three parameters, on the rule this design
 * applies to every new provider method: a later fact is additive here and a
 * fourth parameter breaks every implementation that exists.
 *
 * It deliberately carries no stored values. Describing is not reading: the
 * route merges what is stored onto what the provider declared, so a provider
 * cannot report a different field list depending on what somebody saved, and
 * a secret never has to pass through a provider to be hidden again.
 */
final class SettingsDeclarationContext
{
    private function __construct(
        public readonly string $registryKey,
        public readonly string $scopeType,
        public readonly int $scopeId,
    ) {
    }

    public static function create(string $registryKey, string $scopeType, int $scopeId = 0): self
    {
        return new self($registryKey, $scopeType, $scopeId);
    }

    public function isSiteScope(): bool
    {
        return $this->scopeType === 'site';
    }
}
