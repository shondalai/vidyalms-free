<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Application\Platform;

use Shondalai\VidyaLMS\Core\Domain\Platform\FeatureProvider;
use Shondalai\VidyaLMS\Core\Domain\Platform\FeatureProviderRepository;
use Shondalai\VidyaLMS\Core\Domain\Platform\InactiveProviderCounting;
use Shondalai\VidyaLMS\Core\Domain\Platform\SettingsRepository;
use Shondalai\VidyaLMS\Core\Domain\Shared\ValidationException;
use Shondalai\VidyaLMS\Core\Registry\ProviderPackages;

/**
 * Records which package registered which provider. Registration upserts the
 * row back to active, so a reinstalled package heals its own ledger; an
 * uninstall leaves inactive rows the removal placeholder and the admin UI
 * read.
 *
 * **The whole key set is written only when it changes.** Recording every key
 * of every extension point on every kernel construction meant forty-six
 * upserts before a learner opening a quiz page did any work, for a table whose
 * contents move about once per install. sync() hashes the key set, keeps the
 * hash beside the rows, and writes nothing when the two agree. record() stays
 * for the single-key case a package uses when it registers one provider.
 *
 * **The signature is not the only guard, because it says less than the
 * promise.** A matching hash means the key set has not moved; it does not
 * mean the rows are active. sync() therefore also writes when the package
 * has a row saying it is gone, which is the state a half-finished uninstall
 * or a restored database leaves and which no later boot used to correct.
 */
final class FeatureProviderLedger implements ProviderPackages
{
    /** The settings name the key-set signature of one package is kept under. */
    public const SIGNATURE_PREFIX = 'vidyalms.feature_providers.signature.';

    /** @var array<string, array<string, string>> */
    private array $packagesByPoint = [];

    public function __construct(
        private readonly FeatureProviderRepository $providers,
        private readonly ?SettingsRepository $settings = null,
    ) {
    }

    public function record(
        string $extensionPoint,
        string $providerKey,
        string $package,
        ?string $conformanceResult = null,
        bool $flagged = false,
    ): void {
        if (trim($extensionPoint) === '') {
            throw ValidationException::forField('extension_point', 'An extension point is required.');
        }

        if (trim($providerKey) === '') {
            throw ValidationException::forField('provider_key', 'A provider key is required.');
        }

        if (trim($package) === '') {
            throw ValidationException::forField('package', 'A package name is required.');
        }

        $this->providers->record($extensionPoint, $providerKey, $package, $conformanceResult, $flagged);
        unset($this->packagesByPoint[$extensionPoint]);
    }

    /**
     * Writes one package's whole key set, and only when the set has moved
     * since the last time it was written.
     *
     * A conformance declaration is recorded beside each key it is given for,
     * so a site owner can be told which installed types are unverified rather
     * than the flag living in one page's memory.
     *
     * @param array<string, list<string>>              $points      extension point => provider keys
     * @param array<string, array{0: ?string, 1: bool}> $conformance "point:key" => [result, flagged]
     *
     * @return int how many rows were written; 0 means the ledger already agreed
     */
    public function sync(string $package, array $points, array $conformance = []): int
    {
        if (trim($package) === '') {
            throw ValidationException::forField('package', 'A package name is required.');
        }

        $signature = self::signatureOf($points, $conformance);
        $name = self::SIGNATURE_PREFIX . $package;
        $settings = $this->settings;

        // **The signature alone is not enough to skip the write.** It says
        // the key set has not moved, and the promise this class makes is
        // stronger than that: a package that is registering its providers
        // right now has no inactive rows. A site that reached the inactive
        // state by any route the uninstall hook did not run to the end of (a
        // half-finished uninstall, a database restored from before one, an
        // upgrade that marked rows inactive and died) stayed there for good,
        // because the key set had not moved and every later boot therefore
        // wrote nothing. Six such rows sat on a dev site naming vidyalms-pro
        // as missing while vidyalms-pro was installed and working, which is
        // the ledger reporting the opposite of the truth to the removal
        // placeholder and the admin report at once.
        if ($settings !== null && $settings->find($name)?->value === $signature && !$this->hasInactiveRows($package)) {
            return 0;
        }

        $written = 0;

        foreach ($points as $extensionPoint => $keys) {
            foreach ($keys as $key) {
                [$result, $flagged] = $conformance[$extensionPoint . ':' . $key] ?? [null, false];
                $this->record($extensionPoint, $key, $package, $result, $flagged);
                ++$written;
            }
        }

        $settings?->set($name, $signature);

        return $written;
    }

    public function packageOf(string $extensionPoint, string $providerKey): ?string
    {
        $this->packagesByPoint[$extensionPoint] ??= $this->providers->packagesFor($extensionPoint);

        return $this->packagesByPoint[$extensionPoint][$providerKey] ?? null;
    }

    /**
     * @return list<FeatureProvider>
     */
    public function listActive(): array
    {
        return $this->providers->listActive();
    }

    public function markInactive(string $package): int
    {
        $this->packagesByPoint = [];

        // The signature describes rows that are now inactive, so it has to go
        // with them. Leaving it meant a package that was uninstalled and
        // installed again wrote nothing on its next boot, because its key set
        // had not moved, and the ledger went on saying nobody owned keys the
        // package had just registered. The promise this class makes is that a
        // reinstalled package heals its own ledger, and clearing the signature
        // here is what keeps it.
        $this->settings?->set(self::SIGNATURE_PREFIX . $package, null);

        return $this->providers->markInactive($package);
    }

    /**
     * Whether the ledger holds a row of this package that says the package
     * is gone.
     *
     * One indexed count, and only on the path that was about to write
     * nothing at all, so it replaces forty-six upserts rather than adding to
     * them. A repository that cannot answer leaves the signature as the only
     * guard, which is the behaviour this class had before.
     */
    private function hasInactiveRows(string $package): bool
    {
        $providers = $this->providers;

        return $providers instanceof InactiveProviderCounting && $providers->countInactive($package) > 0;
    }

    /**
     * @param array<string, list<string>>              $points
     * @param array<string, array{0: ?string, 1: bool}> $conformance
     */
    private static function signatureOf(array $points, array $conformance = []): string
    {
        ksort($conformance);
        $normalised = ['~conformance' => $conformance];

        foreach ($points as $extensionPoint => $keys) {
            $sorted = array_values(array_unique($keys));
            sort($sorted);
            $normalised[$extensionPoint] = $sorted;
        }

        ksort($normalised);

        return hash('sha256', json_encode($normalised, JSON_THROW_ON_ERROR));
    }
}
