<?php

declare(strict_types=1);

namespace Shondalai\VidyaLMS\Core\Registry;

/**
 * Which edition a registered provider came from, answered from the package
 * name the ledger already records rather than from a member on the provider.
 *
 * A member would let a third party claim any edition it liked and would put
 * a commercial fact on a technical interface. The ledger knows which package
 * registered a key, and the package name is the honest answer: the core
 * package is free, a package naming itself pro is paid, anything else is a
 * third party and reports as free because nothing here is entitled to say
 * otherwise about somebody else's code.
 */
final class ProviderEdition
{
    public const FREE = 'free';
    public const PRO = 'pro';
    public const BUSINESS = 'business';

    /**
     * The package names this product ships, and the only ones that answer
     * anything but free.
     *
     * **An allowlist and not a substring match.** It used to answer BUSINESS
     * for any name containing "business" and PRO for any containing "pro", so
     * `acme/proctoring-tools` and `northwind-prospect-sync` both reported as
     * Pro and `acme/small-business-forms` reported as Business: a site owner
     * reading the feature ledger was told a third-party proctoring plugin was
     * a Pro feature of this product. The docblock above already said that
     * anything which is not the core or a package naming itself pro reports as
     * free, "because nothing here is entitled to say otherwise about somebody
     * else's code", and this is what makes that true.
     *
     * @var array<string, string>
     */
    public const SHIPPED_PACKAGES = [
        'vidyalms-pro' => self::PRO,
        'vidyalms-business' => self::BUSINESS,
    ];

    private function __construct()
    {
    }

    public static function forPackage(?string $package): string
    {
        if ($package === null || $package === '') {
            return self::FREE;
        }

        return self::SHIPPED_PACKAGES[strtolower(trim($package))] ?? self::FREE;
    }

    public static function of(?ProviderPackages $packages, string $extensionPoint, string $providerKey): string
    {
        if ($packages === null) {
            return self::FREE;
        }

        return self::forPackage($packages->packageOf($extensionPoint, $providerKey));
    }
}
