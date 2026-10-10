<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry\Privacy;

use Shondalai\VidyaLMS\Core\Registry\AbstractProviderRegistry;

/** @extends AbstractProviderRegistry<PrivacyContributorInterface> */
final class PrivacyContributorRegistry extends AbstractProviderRegistry
{
    private ?\Shondalai\VidyaLMS\Core\Domain\Platform\FeatureProviderRepository $ledger = null;
    /** Packages record their expected contributors before registering implementations. */
    /** @var list<string> */
    private array $expected = ['core-organisations'];

    protected function providerInterface(): string
    {
        return PrivacyContributorInterface::class;
    }

    public function expect(string $key): void
    {
        if (!\in_array($key, $this->expected, true)) {
            $this->expected[] = $key;
        }
    }

    public function observeRecordedProviders(\Shondalai\VidyaLMS\Core\Domain\Platform\FeatureProviderRepository $ledger): void
    {
        $this->ledger = $ledger;
    }

    /** @return list<string> */
    public function missing(): array
    {
        $expected = array_unique([...$this->expected, ...array_keys($this->ledger?->packagesFor('privacy_contributor') ?? [])]);
        return array_values(array_filter($expected, fn (string $key): bool => !$this->has($key)));
    }
}
